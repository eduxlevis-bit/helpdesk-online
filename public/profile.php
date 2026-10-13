<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';
requireLogin();

$db = getDB();
$me = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name   = trim($_POST['name'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $phone  = trim($_POST['phone'] ?? '');
    $errors = [];

    if (mb_strlen($name) < 2)                       $errors[] = 'Name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if ($phone !== '' && !valid_phone($phone))      $errors[] = 'Phone number looks invalid — use digits and + ( ) - only.';

    if (!$errors) {
        $chk = $db->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?');
        $chk->execute([$email, (int)$me['id']]);
        if ((int)$chk->fetchColumn() > 0) $errors[] = 'That email is already used by another account.';
    }

    if ($errors) {
        setFlash('error', implode(' ', $errors));
        header('Location: ' . BASE_URL . '/profile.php');
        exit;
    }

    $oldPhone = trim($me['phone'] ?? '');
    $changes  = [];
    if ($name !== $me['name'])                  $changes['Name']  = [$me['name'], $name];
    if (strcasecmp($email, $me['email']) !== 0) $changes['Email'] = [$me['email'], $email];
    if ($phone !== $oldPhone)                   $changes['Phone'] = [$oldPhone !== '' ? $oldPhone : '(none)', $phone !== '' ? $phone : '(none)'];

    if ($changes) {
        $db->prepare('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?')
           ->execute([$name, $email, $phone !== '' ? $phone : null, (int)$me['id']]);

        // Keep the live session in sync — every other screen reads the users table,
        // so the new details appear on all dashboards immediately.
        $_SESSION['user_name']  = $name;
        $_SESSION['user_email'] = $email;

        notify_profile_updated($me, $changes);
        setFlash('success', 'Profile updated. The administrators have been notified of the change.');
    } else {
        setFlash('success', 'Nothing was changed.');
    }
    header('Location: ' . BASE_URL . '/profile.php');
    exit;
}

$stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([(int)$me['id']]);
$user = $stmt->fetch() ?: $me;

$pageTitle = 'My profile';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>My profile</h1>
    <p class="muted">Your name, email and phone are shown to everyone handling your tickets.</p>
  </div>
</div>

<div class="card" style="max-width:720px">
  <form method="post" action="<?= BASE_URL ?>/profile.php">
    <?= csrf_field() ?>
    <div class="form-row">
      <div class="form-group">
        <label for="name">Full name</label>
        <input type="text" id="name" name="name" required
               value="<?= e($_POST['name'] ?? $user['name']) ?>">
      </div>
      <div class="form-group">
        <label for="email">Email (used to sign in)</label>
        <input type="email" id="email" name="email" required
               value="<?= e($_POST['email'] ?? $user['email']) ?>">
      </div>
    </div>
    <div class="form-group">
      <label for="phone">Phone <span class="muted">(optional)</span></label>
      <input type="tel" id="phone" name="phone" placeholder="+254 700 000 000"
             value="<?= e($_POST['phone'] ?? ($user['phone'] ?? '')) ?>">
      <div class="hint">Included in assignment emails so your technician can call you for directions.</div>
    </div>
    <div class="hint" style="margin-top:-6px">
      Changing your name or email alerts the administrators, and the new details show up
      on every ticket and dashboard straight away.
    </div>
    <button class="btn btn-primary" type="submit">Save changes</button>
  </form>
</div>

<div class="card" style="max-width:720px">
  <h2>Account</h2>
  <div class="form-row" style="margin-bottom:0">
    <div class="form-group">
      <label>Role</label>
      <input type="text" value="<?= e(role_label($user['role'])) ?>" disabled>
    </div>
    <div class="form-group">
      <label>Department</label>
      <input type="text" value="<?= e($user['department'] ?? '—') ?>" disabled>
      <div class="hint">Managed by an administrator.</div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
