<?php
$script = basename($_SERVER['SCRIPT_NAME']);
$me     = currentUser();
$nav = [
    'index.php'     => ['Dashboard', ''],
    'tickets.php'   => ['Tickets', 'ticket-no'],
    'ticket-new.php'=> ['Register Problem', 'plus'],
];
if ($me['role'] === 'admin') {
    $nav['users.php']     = ['Users & Technicians', 'users'];
    $nav['categories.php']= ['Categories', 'cat'];
}
$nav['profile.php'] = ['My Profile', ''];
?>
<aside class="sidebar">
  <div class="brand">
    <span class="brand-text"><?= APP_NAME ?></span>
  </div>
  <nav class="nav">
    <?php foreach ($nav as $file => $item): ?>
      <a class="nav-link<?= $script === $file ? ' active' : '' ?>" href="<?= BASE_URL ?>/<?= $file ?>">
        <?= e($item[0]) ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-foot">
    <a class="nav-link<?= $script === 'logout.php' ? ' active' : '' ?>" href="<?= BASE_URL ?>/logout.php">Sign out</a>
  </div>
</aside>
