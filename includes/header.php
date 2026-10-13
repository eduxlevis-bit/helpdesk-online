<?php
require_once __DIR__ . '/helpers.php';
$me = currentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle ?? 'Dashboard') ?> — <?= APP_NAME ?></title>
<link rel="stylesheet" href="<?= style_href() ?>">
<script src="<?= BASE_URL ?>/js/password.js" defer></script>
</head>
<body>
<div class="app-wrapper">
<?php if (isLoggedIn()): ?>
<?php require __DIR__ . '/sidebar.php'; ?>
<?php endif; ?>
<div class="main">
<?php if (isLoggedIn()): ?>
<header class="topbar">
  <div class="topbar-title"><?= e($pageTitle ?? 'Dashboard') ?></div>
  <div class="topbar-user">
    <span class="avatar"><?= e(initials($me['name'])) ?></span>
    <span>
      <strong><?= e($me['name']) ?></strong>
      <small><?= e(role_label($me['role'])) ?></small>
    </span>
  </div>
</header>
<?php endif; ?>
<main class="content">
<?php $flash = getFlash(); if ($flash): ?>
  <div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endif; ?>
