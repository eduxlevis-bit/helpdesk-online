<?php
require_once __DIR__ . '/../includes/helpers.php';
requireRole(['admin']);

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'user';
        $dept     = trim($_POST['department'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $errors   = [];

        if ($name === '')                              $errors[] = 'Name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (!array_key_exists($role, ROLES))           $errors[] = 'Invalid role.';
        if (!valid_phone($phone))                      $errors[] = 'Phone number looks invalid — use digits and + ( ) - only.';
        $errors = array_merge($errors, password_policy_errors($password, $email, $name));

        if (!$errors) {
            $chk = $db->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
            $chk->execute([$email]);
            if ((int)$chk->fetchColumn() > 0) $errors[] = 'That email is already registered.';
        }

        if (!$errors) {
            $stmt = $db->prepare(
                'INSERT INTO users (name, email, password_hash, role, department, phone) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role,
                            $dept ?: null, $phone ?: null]);
            setFlash('success', 'User created.');
            header('Location: ' . BASE_URL . '/users.php');
            exit;
        }
        setFlash('error', implode(' ', $errors));
        header('Location: ' . BASE_URL . '/users.php');
        exit;
    }

    if ($action === 'set_password') {
        $id      = (int)($_POST['id'] ?? 0);
        $pass    = $_POST['password'] ?? '';
        $confirm = $_POST['confirm'] ?? '';
        $errors  = [];

        $chk = $db->prepare('SELECT name, email FROM users WHERE id = ?');
        $chk->execute([$id]);
        $target = $chk->fetch();

        if (!$target) {
            $errors[] = 'Account not found.';
        } elseif ($pass !== $confirm) {
            $errors[] = 'Passwords do not match.';
        } else {
            $errors = password_policy_errors($pass, $target['email'], $target['name']);
        }

        if (!$errors) {
            $db->prepare('UPDATE users SET password_hash = ?, remember_token = NULL, remember_expires = NULL WHERE id = ?')
               ->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
            $db->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$id]);
            setFlash('success', 'Password updated for ' . $target['name'] . '.');
        } else {
            setFlash('error', implode(' ', $errors));
        }
        header('Location: ' . BASE_URL . '/users.php');
        exit;
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id && $id !== (int)currentUser()['id']) {
            $db->prepare('UPDATE users SET active = 1 - active WHERE id = ?')->execute([$id]);
            setFlash('success', 'Account status updated.');
        } else {
            setFlash('error', 'You cannot deactivate your own account.');
        }
        header('Location: ' . BASE_URL . '/users.php');
        exit;
    }
}

$users = $db->query(
    "SELECT u.*,
            (SELECT COUNT(*) FROM tickets t WHERE t.assigned_to = u.id) assigned_count,
            (SELECT COUNT(*) FROM tickets t WHERE t.user_id = u.id)    reported_count,
            (SELECT COUNT(*) FROM tickets t WHERE t.assigned_to = u.id
               AND t.status IN ('Open','Assigned','In Progress'))       open_now,
            (SELECT COUNT(*) FROM tickets t WHERE t.assigned_to = u.id
               AND t.status = 'Resolved'
               AND t.updated_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) done_30
       FROM users u ORDER BY FIELD(u.role,'admin','tech','user'), u.name"
)->fetchAll();

$pageTitle = 'Users & Technicians';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>Users &amp; Technicians</h1>
    <p class="muted">Create accounts and choose who can be assigned to tickets.</p>
  </div>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errors)) ?></div>
<?php endif; ?>

<div class="grid grid-2">
  <div class="card">
    <h2>Add a user / technician</h2>
    <form method="post" action="<?= BASE_URL ?>/users.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="form-group">
        <label for="name">Full name</label>
        <input type="text" id="name" name="name" required value="<?= e($_POST['name'] ?? '') ?>">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="password">Password</label>
          <input type="password" id="password" name="password" required>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="role">Role</label>
          <select id="role" name="role">
            <?php foreach (ROLES as $value => $label): ?>
              <option value="<?= e($value) ?>"<?= ($_POST['role'] ?? 'tech') === $value ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="department">Department</label>
          <input type="text" id="department" name="department" list="dept-list"
                 placeholder="e.g. Infrastructure" value="<?= e($_POST['department'] ?? '') ?>">
          <datalist id="dept-list">
            <?php foreach (departments() as $d): ?>
              <option value="<?= e($d) ?>">
            <?php endforeach; ?>
          </datalist>
          <div class="hint">Technicians only see tickets whose category is routed to this department.</div>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label for="phone">Phone <span class="muted">(optional)</span></label>
          <input type="tel" id="phone" name="phone" placeholder="+254 700 000 000"
                 value="<?= e($_POST['phone'] ?? '') ?>">
          <div class="hint">Shown to the reporter in the assignment email so they can call for directions.</div>
        </div>
      </div>
      <button class="btn btn-primary" type="submit">Create account</button>
    </form>
  </div>

  <div class="card">
    <h2>All accounts (<?= count($users) ?>)</h2>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Name</th><th>Department</th><th>Role</th><th>Workload</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td>
              <strong><?= e($u['name']) ?></strong><br>
              <span class="muted small"><?= e($u['email']) ?></span>
              <?php if (!empty($u['phone'])): ?><br><span class="muted small"><?= e($u['phone']) ?></span><?php endif; ?>
            </td>
            <td class="muted"><?= $u['department'] ? e($u['department']) : '—' ?></td>
            <td><span class="badge badge-role"><?= e(role_label($u['role'])) ?></span></td>
            <td class="muted small">
              <?= (int)$u['reported_count'] ?> raised · <?= (int)$u['assigned_count'] ?> assigned<br>
              <strong><?= (int)$u['open_now'] ?></strong> open now ·
              <?= (int)$u['done_30'] ?> resolved in 30 days
            </td>
            <td><?= $u['active'] ? '<span class="badge badge-on">Active</span>' : '<span class="badge badge-off">Disabled</span>' ?></td>
            <td>
              <a class="btn btn-sm" href="<?= BASE_URL ?>/user-edit.php?id=<?= (int)$u['id'] ?>">Edit</a>
              <details class="pw-details">
                <summary class="btn btn-sm">Password</summary>
                <form method="post" action="<?= BASE_URL ?>/users.php" class="pw-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="set_password">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <label>New password for <?= e($u['name']) ?></label>
                  <input type="password" name="password" required data-meter autocomplete="new-password"
                         placeholder="At least 8 characters">
                  <input type="password" name="confirm" required autocomplete="new-password" placeholder="Repeat password">
                  <div class="hint">8+ characters with a letter and a number. Signs the user out of every device.</div>
                  <button class="btn btn-primary btn-sm" type="submit">Save password</button>
                </form>
              </details>
              <?php if ((int)$u['id'] !== (int)currentUser()['id']): ?>
                <form method="post" action="<?= BASE_URL ?>/users.php" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button class="btn btn-sm <?= $u['active'] ? 'btn-danger' : '' ?>" type="submit">
                    <?= $u['active'] ? 'Disable' : 'Enable' ?>
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
