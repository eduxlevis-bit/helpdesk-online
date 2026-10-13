<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

/* ── Helpers ──────────────────────────────────────────────────────────────── */
function sqlStatements(string $sql): array {
    if (substr($sql, 0, 3) === "\xEF\xBB\xBF") { // strip UTF-8 BOM if present
        $sql = substr($sql, 3);
    }
    $stmts = [];
    $buf   = '';
    $inStr = false;
    $len   = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        if ($ch === "'") {
            if ($inStr && isset($sql[$i + 1]) && $sql[$i + 1] === "'") { $buf .= "''"; $i++; continue; }
            $inStr = !$inStr; $buf .= $ch; continue;
        }
        if (!$inStr && $ch === '-' && isset($sql[$i + 1]) && $sql[$i + 1] === '-') {
            while ($i < $len && $sql[$i] !== "\n") $i++;
            $buf .= ' ';
            continue;
        }
        if (!$inStr && $ch === ';') { $stmts[] = trim($buf); $buf = ''; continue; }
        $buf .= $ch;
    }
    if (trim($buf) !== '') $stmts[] = trim($buf);

    return array_values(array_filter($stmts, function ($s) { return $s !== ''; }));
}

function tableExists(PDO $db, string $table): bool {
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.tables
                          WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function adminCount(PDO $db): int {
    if (!tableExists($db, 'users')) return 0;
    return (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn();
}

/** Brings an older install up to the current structure (safe to run always). */
function ensureStructure(PDO $db): void {
    $statements = [
        'ALTER TABLE tickets ADD COLUMN handling_department VARCHAR(100) DEFAULT NULL AFTER category',
        'ALTER TABLE tickets ADD INDEX idx_team (handling_department)',
        'ALTER TABLE users ADD COLUMN phone VARCHAR(30) DEFAULT NULL AFTER department',
        'ALTER TABLE users ADD COLUMN remember_token VARCHAR(64) DEFAULT NULL AFTER phone',
        'ALTER TABLE users ADD COLUMN remember_expires DATETIME DEFAULT NULL AFTER remember_token',
        'CREATE TABLE IF NOT EXISTS password_resets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_token (token_hash),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
         ) ENGINE=InnoDB',
        'CREATE TABLE IF NOT EXISTS ticket_emails (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ticket_id INT NOT NULL,
            recipient VARCHAR(200) NOT NULL,
            recipient_name VARCHAR(150) DEFAULT NULL,
            subject VARCHAR(255) NOT NULL,
            event VARCHAR(30) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT "queued",
            body MEDIUMTEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
            INDEX idx_ticket (ticket_id)
         ) ENGINE=InnoDB',
    ];
    foreach ($statements as $sql) {
        try { $db->exec($sql); } catch (PDOException $e) { /* already applied */ }
    }
}

/* ── Actions ──────────────────────────────────────────────────────────────── */
$dbError  = '';
$dbOk     = false;
$messages = [];
$db       = null;

try {
    $dsn = 'mysql:host=' . MYSQL_HOST . ';port=' . MYSQL_PORT . ';dbname=' . MYSQL_DB . ';charset=utf8mb4';
    $db  = new PDO($dsn, MYSQL_USER, MYSQL_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $dbOk = true;
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

if ($dbOk && tableExists($db, 'tickets')) {
    ensureStructure($db);
}

if ($dbOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    $installed = adminCount($db) > 0;
    $allowed   = !$installed || isAdmin();

    if ($action === 'import' && $allowed) {
        $file = __DIR__ . '/../db/phpmyadmin_import.sql';
        if (!is_file($file)) {
            $messages[] = ['error', 'db/phpmyadmin_import.sql not found.'];
        } else {
            $sql  = file_get_contents($file);
            $stmts = sqlStatements($sql);
            $done  = 0;
            try {
                foreach ($stmts as $stmt) { $db->exec($stmt); $done++; }
                $messages[] = ['success', "Import complete — $done SQL statements executed."];
            } catch (PDOException $e) {
                $messages[] = ['error', 'Import failed: ' . $e->getMessage()];
            }
        }
    }

    if ($action === 'create_account' && $allowed) {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'admin';
        $dept     = trim($_POST['department'] ?? '');
        $errors   = [];

        if (!tableExists($db, 'users'))       $errors[] = 'Create the tables first.';
        if ($name === '')                     $errors[] = 'Name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (strlen($password) < 6)            $errors[] = 'Password must be at least 6 characters.';
        if (!array_key_exists($role, ROLES))  $errors[] = 'Invalid role.';

        if (!$errors) {
            $chk = $db->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
            $chk->execute([$email]);
            if ((int)$chk->fetchColumn() > 0) $errors[] = 'That email is already registered.';
        }

        if (!$errors) {
            $stmt = $db->prepare(
                'INSERT INTO users (name, email, password_hash, role, department) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $dept ?: null]);
            $messages[] = ['success', role_label($role) . ' account created for ' . $email . '. You can sign in now.'];

            // Setup is finished once an admin exists — try to remove the installer.
            if (adminCount($db) > 0) {
                if (@unlink(__FILE__)) {
                    $messages[] = ['info', 'Setup finished: public/install.php has been deleted.'];
                } else {
                    $messages[] = ['info', 'Setup finished. Delete public/install.php from the server now.'];
                }
            }
        } else {
            foreach ($errors as $err) $messages[] = ['error', $err];
        }
    }

    if ($action === '' || empty($messages)) {
        $messages[] = ['error', 'Nothing was applied — received action "'
            . $action . '", permission ' . ($allowed ? 'granted' : 'denied') . '.'];
    }
}

/* ── State ────────────────────────────────────────────────────────────────── */
$tablesReady = $dbOk && tableExists($db, 'users') && tableExists($db, 'tickets') && tableExists($db, 'ticket_replies');
$admins      = $dbOk ? adminCount($db) : 0;
$canManage   = $dbOk && ($admins === 0 || isAdmin());
$accounts    = [];
if ($dbOk && $tablesReady) {
    $accounts = $db->query('SELECT id, name, email, role, department, active FROM users
                            ORDER BY FIELD(role,"admin","tech","user"), name')->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Installer — <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= style_href() ?>">
</head>
<body>
<div class="auth-wrap" style="align-items:flex-start;padding:40px 20px">
  <div class="auth-card" style="max-width:760px">
    <div class="auth-brand">
      <strong><?= APP_NAME ?> — Installer</strong>
    </div>
    <h1>Setup</h1>
    <p class="sub">Creates the tables and the first access accounts on your hosting database.</p>

    <?php foreach ($messages as $m): ?>
      <div class="alert alert-<?= $m[0] === 'error' ? 'error' : ($m[0] === 'success' ? 'success' : 'info') ?>">
        <?= e($m[1]) ?>
      </div>
    <?php endforeach; ?>

    <div class="card">
      <h2>1. Database connection</h2>
      <?php if (!$dbOk): ?>
        <div class="alert alert-error">
          Connection failed: <?= e($dbError) ?><br>
          Set the credentials in <code>config/.env</code> (rename <code>.env.example</code> to
          <code>.env</code> first if you have not already, then MYSQL_HOST, MYSQL_DB, MYSQL_USER, MYSQL_PASS)
          to match the values your host gave you, then reload this page.
        </div>
      <?php else: ?>
        <div class="alert alert-success">Connected to <?= e(MYSQL_HOST) ?> / <?= e(MYSQL_DB) ?> as <?= e(MYSQL_USER) ?></div>
        <dl class="kv">
          <dt>Tables</dt>
          <dd><?= $tablesReady
                ? '<span class="badge badge-on">users, tickets, ticket_replies ready</span>'
                : '<span class="badge badge-off">not created yet</span>' ?></dd>
          <dt>Admin accounts</dt><dd><?= (int)$admins ?></dd>
        </dl>
      <?php endif; ?>
    </div>

    <?php if ($dbOk): ?>
    <div class="card">
      <h2>2. Create tables</h2>
      <?php if ($tablesReady && !$canManage): ?>
        <p class="muted">Tables already exist. Sign in as an administrator to run the import again.</p>
      <?php else: ?>
        <p class="muted small">Runs <code>db/phpmyadmin_import.sql</code> (users, tickets, ticket_replies + default accounts).</p>
        <form method="post" action="install.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="import">
          <button class="btn btn-primary" type="submit"><?= $tablesReady ? 'Re-run import' : 'Create tables now' ?></button>
        </form>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>3. Access accounts</h2>
      <?php if (!$canManage): ?>
        <div class="alert alert-info">Accounts already exist. Sign in and manage them from <a href="<?= BASE_URL ?>/users.php">Users &amp; Technicians</a>.</div>
      <?php else: ?>
        <p class="muted small">
          Create the first <strong>administrator</strong>, then add <strong>technicians</strong> and staff users.
          Roles: admin (assign tickets, manage users) · tech (work on tickets) · user (register problems).
        </p>
        <form method="post" action="install.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create_account">
          <div class="form-row">
            <div class="form-group">
              <label for="name">Full name</label>
              <input type="text" id="name" name="name" required>
            </div>
            <div class="form-group">
              <label for="email">Email (login)</label>
              <input type="email" id="email" name="email" required>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label for="password">Password</label>
              <input type="password" id="password" name="password" required minlength="6">
            </div>
            <div class="form-group">
              <label for="role">Role</label>
              <select id="role" name="role">
                <?php foreach (ROLES as $value => $label): ?>
                  <option value="<?= e($value) ?>"<?= $value === 'admin' ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="department">Department</label>
              <input type="text" id="department" name="department" placeholder="IT Department">
            </div>
          </div>
          <button class="btn btn-primary" type="submit">Create account</button>
        </form>
      <?php endif; ?>

      <?php if ($accounts): ?>
        <div class="table-wrap" style="margin-top:18px">
          <table>
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Department</th></tr></thead>
            <tbody>
            <?php foreach ($accounts as $a): ?>
              <tr>
                <td><?= e($a['name']) ?></td>
                <td class="muted"><?= e($a['email']) ?></td>
                <td><span class="badge badge-role"><?= e(role_label($a['role'])) ?></span></td>
                <td class="muted"><?= e($a['department'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <h2>4. Finish</h2>
      <p class="muted small">
        Delete <code>public/install.php</code> from the server, then sign in at
        <a href="<?= BASE_URL ?>/login.php"><?= BASE_URL ?>/login.php</a>.
      </p>
      <a class="btn" href="<?= BASE_URL ?>/login.php">Go to sign in</a>
    </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
