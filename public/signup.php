<?php
require_once __DIR__ . '/../includes/helpers.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $dept     = trim($_POST['department'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';
    $errors   = [];

    if ($name === '')                                 $errors[] = 'Full name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))   $errors[] = 'A valid email address is required.';
    if ($password !== $confirm)                       $errors[] = 'Passwords do not match.';
    if (!valid_phone($phone))                         $errors[] = 'Phone number looks invalid — use digits and + ( ) - only.';
    $errors = array_merge($errors, password_policy_errors($password, $email, $name));

    $db = getDB();
    if (!$errors) {
        $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ((int)$stmt->fetchColumn() > 0) {
            $errors[] = 'An account with that email already exists.';
        }
    }

    if (!$errors) {
        $stmt = $db->prepare(
            "INSERT INTO users (name, email, password_hash, role, department, phone)
             VALUES (?, ?, ?, 'user', ?, ?)"
        );
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $dept ?: null, $phone ?: null]);
        setFlash('success', 'Account created. Please sign in.');
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}
$pageTitle = 'Create account';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create account — <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= style_href() ?>">
</head>
<body>
<div class="auth-split">
  <div class="auth-left">
    <div class="auth-top">
      <div class="auth-logo">
        <span class="word"><?= APP_NAME ?></span>
      </div>
      <a class="auth-top-link" href="<?= BASE_URL ?>/login.php">Sign in</a>
    </div>

    <div class="auth-form">
      <h1>Create Account</h1>
      <p class="auth-sub">Register to log <strong>IT problems</strong> and track progress</p>

      <?php if (!empty($errors)): ?>
        <div class="alert alert-error"><?= implode('<br>', array_map('e', $errors)) ?></div>
      <?php endif; ?>

      <form method="post" action="<?= BASE_URL ?>/signup.php">
        <?= csrf_field() ?>
        <div class="form-group">
          <label for="name">Full name <span class="req">*</span></label>
          <input type="text" id="name" name="name" required placeholder="Enter your full name"
                 value="<?= e($_POST['name'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label for="email">Email address <span class="req">*</span></label>
          <input type="email" id="email" name="email" required placeholder="Enter your email address"
                 value="<?= e($_POST['email'] ?? '') ?>">
        </div>

        <div class="form-row">
          <div class="form-group">
            <label for="phone">Phone <span class="muted">(optional)</span></label>
            <input type="tel" id="phone" name="phone" placeholder="+254 700 000 000"
                   value="<?= e($_POST['phone'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="department">Department <span class="muted">(optional)</span></label>
            <input type="text" id="department" name="department" list="dept-list"
                   placeholder="e.g. Finance" value="<?= e($_POST['department'] ?? '') ?>">
            <datalist id="dept-list">
              <?php foreach (departments() as $d): ?><option value="<?= e($d) ?>"><?php endforeach; ?>
            </datalist>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label for="password">Password <span class="req">*</span></label>
            <div class="pw-wrap">
              <input type="password" id="password" name="password" required data-meter
                     placeholder="At least 8 characters">
              <button class="pw-toggle" type="button" data-pw="password">Show Password 👁</button>
            </div>
          </div>
          <div class="form-group">
            <label for="confirm">Confirm password <span class="req">*</span></label>
            <div class="pw-wrap">
              <input type="password" id="confirm" name="confirm" required placeholder="Repeat the password">
              <button class="pw-toggle" type="button" data-pw="confirm">Show Password 👁</button>
            </div>
          </div>
        </div>

        <button class="btn-green" type="submit">Create Account</button>
      </form>

      <div class="auth-or">Or continue with</div>

      <a class="btn-soft" href="<?= BASE_URL ?>/login.php">Already registered? Sign in</a>
    </div>
  </div>

  <div class="auth-right auth-photo">
    <img src="<?= BASE_URL ?>/img/login-hero.jpg" alt="">
    <div class="auth-caption">
      <h2>Report it once - we take it from there.</h2>
      <p>Your problem is routed to the right department and you are emailed every time it moves forward.</p>
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
