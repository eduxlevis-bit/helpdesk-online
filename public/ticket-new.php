<?php
require_once __DIR__ . '/../includes/helpers.php';
requireLogin();

$db = getDB();
$me = currentUser();
$catList = categories();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $title       = trim($_POST['title'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $priority    = $_POST['priority'] ?? 'Medium';
    $description = trim($_POST['description'] ?? '');
    $errors      = [];
    $category    = categoryById($category_id);

    if ($title === '')                          $errors[] = 'A short title is required.';
    if (mb_strlen($title) > 200)                $errors[] = 'Title is too long (max 200 characters).';
    if (!$category)                             $errors[] = 'Please choose a category from the list.';
    if (!in_array($priority, PRIORITIES, true)) $errors[] = 'Please choose a valid priority.';
    if ($description === '')                    $errors[] = 'Please describe the problem.';
    if (!$catList)                              $errors[] = 'No categories are configured yet — ask an administrator.';

    if (!$errors) {
        $ticket_no = next_ticket_no($db);
        $stmt = $db->prepare(
            "INSERT INTO tickets (ticket_no, user_id, category, handling_department, priority, title, description)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $ticket_no, $me['id'], $category['name'], $category['department'],
            $priority, $title, $description,
        ]);
        setFlash('success', "Problem registered as {$ticket_no} — routed to the {$category['department']} team.");
        header('Location: ' . BASE_URL . '/ticket-view.php?id=' . $db->lastInsertId());
        exit;
    }
}

$pageTitle = 'Register a problem';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>Register a problem</h1>
    <p class="muted">Pick a category — it decides which department handles your ticket.</p>
  </div>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('e', $errors)) ?></div>
<?php endif; ?>

<div class="card" style="max-width:820px">
  <?php if (!$catList): ?>
    <div class="alert alert-info">No categories are configured yet. An administrator can add them under Categories.</div>
  <?php endif; ?>
  <form method="post" action="<?= BASE_URL ?>/ticket-new.php">
    <?= csrf_field() ?>
    <div class="form-row">
      <div class="form-group">
        <label for="category_id">Category</label>
        <select id="category_id" name="category_id" required>
          <option value="">— select —</option>
          <?php foreach ($catList as $c): ?>
            <option value="<?= (int)$c['id'] ?>"<?= (int)($_POST['category_id'] ?? 0) === (int)$c['id'] ? ' selected' : '' ?>>
              <?= e($c['name']) ?> — <?= e($c['department']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="hint">Routed to the department shown after the category name.</div>
      </div>
      <div class="form-group">
        <label for="priority">Priority</label>
        <select id="priority" name="priority" required>
          <?php foreach (PRIORITIES as $p): ?>
            <option value="<?= e($p) ?>"<?= ($_POST['priority'] ?? 'Medium') === $p ? ' selected' : '' ?>><?= e($p) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="hint">Critical = service completely down.</div>
      </div>
    </div>

    <div class="form-group">
      <label for="title">Short title</label>
      <input type="text" id="title" name="title" required maxlength="200"
             placeholder="e.g. Printer on 3rd floor not responding"
             value="<?= e($_POST['title'] ?? '') ?>">
    </div>

    <div class="form-group">
      <label for="description">Description</label>
      <textarea id="description" name="description" required
        placeholder="What happened? When did it start? What have you already tried?"><?= e($_POST['description'] ?? '') ?></textarea>
    </div>

    <button class="btn btn-primary" type="submit"<?= $catList ? '' : ' disabled' ?>>Submit ticket</button>
    <a class="btn" href="<?= BASE_URL ?>/tickets.php">Cancel</a>
  </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
