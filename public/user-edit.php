<?php
require_once __DIR__ . '/../includes/helpers.php';
requireRole(['admin']);

$db = getDB();
$id  = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $id         = (int)($_POST['id'] ?? 0);
    $name       = trim($_POST['name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $role       = $_POST['role'] ?? 'user';
    $department = trim($_POST['department'] ?? '');
    $phone      = trim($_POST['phone'] ?? '');
    $password   = $_POST['password'] ?? '';
    $errors     = [];

    if ($name === '')                                $errors[] = 'Name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))  $errors[] = 'Valid email is required.';
    if (!array_key_exists($role, ROLES))             $errors[] = 'Invalid role.';
    if (!valid_phone($phone))                        $errors[] = 'Phone number looks invalid — use digits and + ( ) - only.';
    if ($password !== '') {
        $errors = array_merge($errors, password_policy_errors($password, $email, $name));
    }

    if (!$errors) {
        $chk = $db->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?');
        $chk->execute([$email, $id]);
        if ((int)$chk->fetchColumn() > 0) $errors[] = 'That email is already used by another account.';
    }

    if (!$errors) {
        $stmt = $db->prepare('UPDATE users SET name = ?, email = ?, role = ?, department = ?, phone = ? WHERE id = ?');
        $stmt->execute([$name, $email, $role, $department !== '' ? $department : null, $phone ?: null, $id]);

        if ($password !== '') {
            $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        }

        // Keep an active session in sync if the admin edited their own record
        if ($id === (int)currentUser()['id']) {
            $_SESSION['user_name']  = $name;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_role']  = $role;
            $_SESSION['user_dept']  = $department;
        }

        setFlash('success', 'Account updated.');
        header('Location: ' . BASE_URL . '/users.php');
        exit;
    }
    setFlash('error', implode(' ', $errors));
    header('Location: ' . BASE_URL . '/user-edit.php?id=' . $id);
    exit;
}

$stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('error', 'Account not found.');
    header('Location: ' . BASE_URL . '/users.php');
    exit;
}

$pageTitle = 'Edit account';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>Edit account</h1>
    <p class="muted"><?= e($user['email']) ?></p>
  </div>
  <a class="btn" href="<?= BASE_URL ?>/users.php">&larr; Back to users</a>
</div>

<div class="card" style="max-width:720px">
  <form method="post" action="<?= BASE_URL ?>/user-edit.php">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$user['id'] ?>">
    <div class="form-row">
      <div class="form-group">
        <label for="name">Full name</label>
        <input type="text" id="name" name="name" required value="<?= e($user['name']) ?>">
      </div>
      <div class="form-group">
        <label for="email">Email (login)</label>
        <input type="email" id="email" name="email" required value="<?= e($user['email']) ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label for="role">Role</label>
        <select id="role" name="role">
          <?php foreach (ROLES as $value => $label): ?>
            <option value="<?= e($value) ?>"<?= $user['role'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="department">Department</label>
        <input type="text" id="department" name="department" list="dept-list"
               value="<?= e($user['department'] ?? '') ?>">
        <datalist id="dept-list">
          <?php foreach (departments() as $d): ?>
            <option value="<?= e($d) ?>">
          <?php endforeach; ?>
        </datalist>
        <div class="hint">Technicians only see tickets routed to this department.</div>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label for="phone">Phone <span class="muted">(optional)</span></label>
        <input type="tel" id="phone" name="phone" placeholder="+254 700 000 000"
               value="<?= e($user['phone'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label for="password">New password <span class="muted">(leave blank to keep the current one)</span></label>
        <input type="password" id="password" name="password" autocomplete="new-password" data-meter
               placeholder="At least 8 characters">
      </div>
    </div>
    <div class="hint" style="margin-top:-6px">The phone number is included in the assignment email so the reporter can call for directions.</div>
    <button class="btn btn-primary" type="submit">Save changes</button>
    <a class="btn" href="<?= BASE_URL ?>/users.php">Cancel</a>
  </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
