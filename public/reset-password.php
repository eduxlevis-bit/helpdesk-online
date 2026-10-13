<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

function reset_row(string $token): ?array {
    if ($token === '') return null;
    $stmt = getDB()->prepare(
        'SELECT r.user_id, u.name, u.email
           FROM password_resets r
           JOIN users u ON u.id = r.user_id
          WHERE r.token_hash = ? AND r.expires_at > NOW() AND u.active = 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$row   = reset_row($token);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if (!$row) {
        $error = 'This reset link is invalid or has expired. Please request a new one.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif ($policy = password_policy_errors($password, $row['email'], $row['name'])) {
        $error = implode(' ', $policy);
    } else {
        $db = getDB();
        $db->prepare('UPDATE users SET password_hash = ?, remember_token = NULL, remember_expires = NULL WHERE id = ?')
           ->execute([password_hash($password, PASSWORD_DEFAULT), (int)$row['user_id']]);
        $db->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([(int)$row['user_id']]);
        clearRememberToken((int)$row['user_id']);

        setFlash('success', 'Password updated. Sign in with your new password.');
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}
$pageTitle = 'Reset password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset password — <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= style_href() ?>">
</head>
<body>
<div class="auth-split">
  <div class="auth-left">
    <div class="auth-top">
      <div class="auth-logo">
        <span class="word"><?= APP_NAME ?></span>
      </div>
      <a class="auth-top-link" href="<?= BASE_URL ?>/login.php">Back to sign in</a>
    </div>

    <div class="auth-form">
      <h1>Set New Password</h1>
      <p class="auth-sub">For <strong><?= $row ? e($row['email']) : 'your account' ?></strong></p>

      <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
      <?php endif; ?>

      <?php if ($row): ?>
        <form method="post" action="<?= BASE_URL ?>/reset-password.php">
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= e($token) ?>">
          <div class="form-group">
            <label for="password">New password <span class="req">*</span></label>
            <div class="pw-wrap">
              <input type="password" id="password" name="password" required data-meter
                     placeholder="At least 8 characters">
              <button class="pw-toggle" type="button" data-pw="password">Show Password 👁</button>
            </div>
          </div>
          <div class="form-group">
            <label for="confirm">Confirm new password <span class="req">*</span></label>
            <div class="pw-wrap">
              <input type="password" id="confirm" name="confirm" required placeholder="Repeat the password">
              <button class="pw-toggle" type="button" data-pw="confirm">Show Password 👁</button>
            </div>
          </div>
          <button class="btn-green" type="submit">Update password</button>
        </form>
      <?php else: ?>
        <a class="btn-green" href="<?= BASE_URL ?>/forgot-password.php">Request a new link</a>
      <?php endif; ?>

      <div class="auth-or">Or continue with</div>

      <a class="btn-soft" href="<?= BASE_URL ?>/login.php">Back to sign in</a>
    </div>
  </div>

  <div class="auth-right auth-photo">
    <img src="<?= BASE_URL ?>/img/login-hero.jpg" alt="">
    <div class="auth-caption">
      <h2>Pick a new password and you are done.</h2>
      <p>Updating your password signs out every other device for safety.</p>
    </div>
  </div>
</div>
<script>
document.querySelectorAll('.pw-toggle').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var input = document.getElementById(btn.dataset.pw);
    var showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    btn.textContent = showing ? 'Show Password 👁' : 'Hide Password 🙈';
  });
});
</script>
<script src="<?= BASE_URL ?>/js/password.js" defer></script>
</body>
</html>
