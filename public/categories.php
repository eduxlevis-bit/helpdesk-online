<?php
require_once __DIR__ . '/../includes/helpers.php';
requireRole(['admin']);

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $dept = trim($_POST['department'] ?? '');
        if ($name === '' || $dept === '') {
            setFlash('error', 'Category name and department are both required.');
        } else {
            $chk = $db->prepare('SELECT COUNT(*) FROM categories WHERE name = ?');
            $chk->execute([$name]);
            if ((int)$chk->fetchColumn() > 0) {
                setFlash('error', 'That category already exists.');
            } else {
                $db->prepare('INSERT INTO categories (name, department) VALUES (?, ?)')->execute([$name, $dept]);
                setFlash('success', "Category \"{$name}\" added — tickets will route to {$dept}.");
            }
        }
    }

    if ($action === 'update') {
        $id   = (int)($_POST['id'] ?? 0);
        $dept = trim($_POST['department'] ?? '');
        if ($id && $dept !== '') {
            $db->prepare('UPDATE categories SET department = ? WHERE id = ?')->execute([$dept, $id]);
            setFlash('success', 'Routing updated — new tickets will go to ' . $dept . '.');
        } else {
            setFlash('error', 'Department cannot be empty.');
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $db->prepare('UPDATE categories SET active = 1 - active WHERE id = ?')->execute([$id]);
            setFlash('success', 'Category status updated.');
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $chk = $db->prepare('SELECT COUNT(*) FROM tickets WHERE category = (SELECT name FROM categories WHERE id = ?)');
            $chk->execute([$id]);
            $used = (int)$chk->fetchColumn();
            if ($used > 0) {
                setFlash('error', "Cannot delete — {$used} ticket(s) use this category. Deactivate it instead.");
            } else {
                $db->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
                setFlash('success', 'Category deleted.');
            }
        }
    }

    header('Location: ' . BASE_URL . '/categories.php');
    exit;
}

$rows = $db->query(
    'SELECT c.*, (SELECT COUNT(*) FROM tickets t WHERE t.category = c.name) AS ticket_count
       FROM categories c ORDER BY c.active DESC, c.name'
)->fetchAll();

$maxCatTickets = 1;
foreach ($rows as $r) {
    $maxCatTickets = max($maxCatTickets, (int)$r['ticket_count']);
}

$maxCatTickets = 1;
foreach ($rows as $r) {
    $maxCatTickets = max($maxCatTickets, (int)$r['ticket_count']);
}

$pageTitle = 'Categories';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>Categories</h1>
    <p class="muted">Each category decides which department sees and handles the ticket.</p>
  </div>
</div>

<div class="grid grid-2">
  <div class="card">
    <h2>Add a category</h2>
    <form method="post" action="<?= BASE_URL ?>/categories.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create">
      <div class="form-group">
        <label for="name">Category name</label>
        <input type="text" id="name" name="name" required maxlength="60"
               placeholder="e.g. VPN & Remote access">
      </div>
      <div class="form-group">
        <label for="department">Handling department</label>
        <input type="text" id="department" name="department" required list="dept-list"
               placeholder="e.g. Infrastructure">
        <datalist id="dept-list">
          <?php foreach (departments() as $d): ?>
            <option value="<?= e($d) ?>">
          <?php endforeach; ?>
        </datalist>
        <div class="hint">Technicians in this department will see these tickets on their dashboard.</div>
      </div>
      <button class="btn btn-primary" type="submit">Add category</button>
    </form>
  </div>

  <div class="card">
    <h2>Existing categories (<?= count($rows) ?>)</h2>
    <?php if (!$rows): ?>
      <div class="empty">No categories yet — add one on the left.</div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>Category</th><th>Routes to</th><th>Tickets</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $c): ?>
          <tr>
            <td><strong><?= e($c['name']) ?></strong></td>
            <td>
              <form method="post" action="<?= BASE_URL ?>/categories.php" class="filters" style="margin:0">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <input type="text" name="department" value="<?= e($c['department']) ?>" style="min-width:150px">
                <button class="btn btn-sm" type="submit">Save</button>
              </form>
            </td>
            <td class="muted" style="min-width:130px">
              <?= (int)$c['ticket_count'] ?> ticket(s)
              <span class="bar-track" style="display:block;margin-top:5px">
                <span class="bar-fill" style="width: <?= (int)round((int)$c['ticket_count'] / $maxCatTickets * 100) ?>%"></span>
              </span>
            </td>
            <td><?= $c['active'] ? '<span class="badge badge-on">Active</span>'
                                : '<span class="badge badge-off">Hidden</span>' ?></td>
            <td>
              <form method="post" action="<?= BASE_URL ?>/categories.php" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="btn btn-sm" type="submit"><?= $c['active'] ? 'Hide' : 'Show' ?></button>
              </form>
              <form method="post" action="<?= BASE_URL ?>/categories.php" style="display:inline"
                    onsubmit="return confirm('Delete this category?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint">Changing the department only affects <strong>new</strong> tickets — existing tickets keep the team they were routed to.</p>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
