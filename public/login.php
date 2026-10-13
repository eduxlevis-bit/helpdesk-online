<?php
require_once __DIR__ . '/../includes/auth.php';

/* ── Handle login ─────────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember']);

    $stmt = getDB()->prepare('SELECT * FROM users WHERE email = ? AND active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']    = (int)$user['id'];
        $_SESSION['user_name']  = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role']  = $user['role'];
        $_SESSION['user_dept']  = $user['department'] ?? '';
        $_SESSION['remembered'] = false;

        if ($remember) {
            issueRememberToken((int)$user['id']);
        } else {
            clearRememberToken((int)$user['id']);
        }
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
    $error = 'Invalid email or password.';
}

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$pageTitle = 'Sign in';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign in — <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= style_href() ?>">
</head>
<body>
<div class="auth-split">
  <div class="auth-left">
    <div class="auth-top">
      <div class="auth-logo">
        <span class="word"><?= APP_NAME ?></span>
      </div>
      <a class="auth-top-link" href="<?= BASE_URL ?>/signup.php">Create account</a>
    </div>

    <div class="auth-form">
      <h1>One Login</h1>
      <p class="auth-sub">All <strong>IT helpdesk</strong> services</p>

      <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
      <?php endif; ?>
      <?php if ($flash = getFlash()): ?>
        <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
      <?php endif; ?>

      <form method="post" action="<?= BASE_URL ?>/login.php">
        <?= csrf_field() ?>
        <div class="form-group">
          <label for="email">Email address <span class="req">*</span></label>
          <input type="email" id="email" name="email" required autofocus
                 placeholder="Enter your email address" value="<?= e($_POST['email'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label for="password">Password <span class="req">*</span></label>
          <div class="pw-wrap">
            <input type="password" id="password" name="password" required placeholder="Password">
            <button class="pw-toggle" type="button" data-pw="password">Show Password </button>
          </div>
        </div>

        <div class="auth-row">
          <label class="auth-check">
            <input type="checkbox" name="remember" value="1"<?= !empty($_POST['remember']) ? ' checked' : '' ?>>
            Remember for 30 days
          </label>
          <a href="<?= BASE_URL ?>/forgot-password.php">Forgot Password</a>
        </div>

        <button class="btn-green" type="submit">Sign In</button>
      </form>

      <div class="auth-or">Or continue with</div>

      <a class="btn-soft" href="<?= BASE_URL ?>/signup.php">New here? Create an account</a>
    </div>
  </div>

<div class="auth-right auth-photo">
  <img src="<?= BASE_URL ?>/img/login-hero.jpg" alt="">
  <div class="auth-caption">
    <h2>IT support now at your fingertips.</h2>
    <p>Register a problem, follow it in real time and get notified by email every step of the way.</p>
  </div>
</div>
</div>
<script>
document.querySelectorAll('.pw-toggle').forEach(function (btn) {
  btn.addEventListener('click', function () {
    var input = document.getElementById(btn.dataset.pw);
    var showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    btn.textContent = showing ? 'Show Password ' : 'Hide Password ';
  });
});
</script>
</body>
</html>
