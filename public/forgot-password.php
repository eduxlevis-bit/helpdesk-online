<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $sent  = true;

    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = getDB()->prepare("SELECT id, name, email FROM users WHERE email = ? AND active = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            getDB()->prepare('DELETE FROM password_resets WHERE user_id = ? OR expires_at < NOW()')
                ->execute([$user['id']]);

            $token = bin2hex(random_bytes(32));
            getDB()->prepare(
                'INSERT INTO password_resets (user_id, token_hash, expires_at)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
            )->execute([
                (int)$user['id'],
                hash('sha256', $token),
            ]);

            $url = absolute_url('/reset-password.php?token=' . $token);
            $body = '<p>Hi ' . e($user['name']) . ',</p>'
                . '<p>We received a request to reset your password. This link expires in <strong>1 hour</strong>.</p>'
                . '<p style="margin:20px 0 6px;"><a href="' . e($url) . '" style="background:#16a34a;color:#ffffff;'
                . 'text-decoration:none;padding:12px 22px;border-radius:999px;font-weight:700;">'
                . 'Set a new password</a></p>'
                . '<p style="color:#777777;font-size:13px;">If the button does not work, paste this link into your browser:</p>'
                . '<p style="font-size:13px;word-break:break-all;">' . e($url) . '</p>'
                . '<p style="color:#777777;font-size:13px;">If you did not request this, you can safely ignore this email.</p>';

            sendMail($user['email'], '[' . APP_NAME . '] Reset your password',
                     mail_template('Reset your password', $body));
        }
    }
}
$pageTitle = 'Forgot password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot password — <?= APP_NAME ?></title>
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
      <h1>Forgot Password</h1>
      <p class="auth-sub">We will email you a <strong>secure reset link</strong></p>

      <?php if ($sent): ?>
        <div class="alert alert-success">
          If an account exists for that address, a reset link is on its way.
          The link is valid for 1 hour.
        </div>
      <?php else: ?>
        <form method="post" action="<?= BASE_URL ?>/forgot-password.php">
          <?= csrf_field() ?>
          <div class="form-group">
            <label for="email">Email address <span class="req">*</span></label>
            <input type="email" id="email" name="email" required autofocus
                   placeholder="Enter your registered email" value="<?= e($_POST['email'] ?? '') ?>">
          </div>
          <button class="btn-green" type="submit">Send reset link</button>
        </form>
      <?php endif; ?>

      <div class="auth-or">Or continue with</div>

      <a class="btn-soft" href="<?= BASE_URL ?>/login.php">Back to sign in</a>
    </div>
  </div>

  <div class="auth-right auth-photo">
    <img src="<?= BASE_URL ?>/img/login-hero.jpg" alt="">
    <div class="auth-caption">
      <h2>Locked out? You are back in a minute.</h2>
      <p>Reset links are single-use and expire quickly, so nobody else can reach your account.</p>
    </div>
  </div>
</div>
</body>
</html>
