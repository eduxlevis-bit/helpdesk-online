<?php
require_once __DIR__ . '/../includes/helpers.php';
requireRole(['admin', 'tech', 'user']);

$db = getDB();
$me = currentUser();

$status = $_GET['status'] ?? '';
$q      = trim($_GET['q'] ?? '');
$dept   = trim($_GET['dept'] ?? '');
$mine   = isset($_GET['mine']) ? 1 : 0;
$sort   = $_GET['sort'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$per    = 12;

list($visSql, $visParams) = visible_tickets_sql('t');

$mineSql = $deptSql = $qSql = '';
$mineParams = $deptParams = $qParams = [];
if ($mine && $me['role'] !== 'user') {
    $mineSql = ' AND t.assigned_to = ?';
    $mineParams[] = $me['id'];
}
if ($dept !== '' && ($me['role'] === 'admin' || $dept === $me['department'])) {
    $deptSql = ' AND t.handling_department = ?';
    $deptParams[] = $dept;
}
if ($q !== '') {
    $qSql  = ' AND (t.title LIKE ? OR t.ticket_no LIKE ? OR t.description LIKE ?)';
    $like  = '%' . $q . '%';
    $qParams = [$like, $like, $like];
}
$filters    = $mineSql . $deptSql . $qSql;
$filterVals = array_merge($mineParams, $deptParams, $qParams);

/* Count per status for the chips (status filter itself excluded). */
$countStmt = $db->prepare("SELECT t.status, COUNT(*) c FROM tickets t
                            WHERE 1=1 {$visSql}{$filters} GROUP BY t.status");
$countStmt->execute(array_merge($visParams, $filterVals));
$counts = array_fill_keys(STATUSES, 0);
foreach ($countStmt->fetchAll() as $r) {
    $counts[$r['status']] = (int)$r['c'];
}
$countsAll = array_sum($counts);

/* Sorting */
$order = "ORDER BY FIELD(t.status,'Open','Assigned','In Progress','Resolved','Closed'), t.updated_at DESC";
if ($sort === 'oldest')      $order = 'ORDER BY t.updated_at ASC';
elseif ($sort === 'priority')$order = "ORDER BY FIELD(t.priority,'Critical','High','Medium','Low'), t.updated_at DESC";
elseif ($sort === 'newest')  $order = 'ORDER BY t.created_at DESC';

$sql = "SELECT t.*, u.name reporter, a.name assignee
          FROM tickets t
          JOIN users u ON u.id = t.user_id
     LEFT JOIN users a ON a.id = t.assigned_to
         WHERE 1=1 {$visSql}{$filters}";
$params = array_merge($visParams, $filterVals);

if ($status !== '' && in_array($status, STATUSES, true)) {
    $sql   .= ' AND t.status = ?';
    $params[] = $status;
}
$sql .= ' ' . $order;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

$total = count($tickets);
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);
$slice = array_slice($tickets, ($page - 1) * $per, $per);
$from  = $total ? ($page - 1) * $per + 1 : 0;
$to    = min($total, $page * $per);

/** tickets.php link that keeps the current filters. */
function tqs(array $over = []): string {
    $keep = [];
    foreach (array_merge($_GET, $over) as $k => $v) {
        if (!in_array($k, ['status', 'q', 'dept', 'mine', 'sort', 'page'], true)) continue;
        if ($v === '' || $v === null) continue;
        $keep[$k] = $v;
    }
    if ((int)($keep['page'] ?? 1) <= 1) unset($keep['page']);
    return BASE_URL . '/tickets.php' . ($keep ? '?' . http_build_query($keep) : '');
}

$pageTitle = 'Tickets';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>Tickets</h1>
    <p class="muted"><?= $total ? "Showing {$from}&ndash;{$to} of {$total} ticket(s)" : 'No tickets found' ?></p>
  </div>
  <a class="btn btn-primary" href="<?= BASE_URL ?>/ticket-new.php">+ Register a problem</a>
</div>

<div class="chips">
  <a class="chip-f<?= $status === '' ? ' active' : '' ?>" href="<?= e(tqs(['status' => '', 'page' => ''])) ?>">
    All <b><?= $countsAll ?></b>
  </a>
  <?php foreach (STATUSES as $s): ?>
    <a class="chip-f<?= $status === $s ? ' active' : '' ?>" href="<?= e(tqs(['status' => $s, 'page' => ''])) ?>">
      <?= e($s) ?> <b><?= $counts[$s] ?></b>
    </a>
  <?php endforeach; ?>
</div>

<form class="filters card" method="get" action="<?= BASE_URL ?>/tickets.php">
  <div class="form-group">
    <label for="q">Search</label>
    <input type="text" id="q" name="q" placeholder="Title, number or description" value="<?= e($q) ?>">
  </div>
  <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
  <?php if ($me['role'] !== 'user'): ?>
  <div class="form-group">
    <label for="mine">Assignee</label>
    <select id="mine" name="mine">
      <option value="">Everyone</option>
      <option value="1"<?= $mine ? ' selected' : '' ?>>Assigned to me</option>
    </select>
  </div>
  <?php endif; ?>
  <?php if ($me['role'] === 'admin'): ?>
  <div class="form-group">
    <label for="dept">Department</label>
    <select id="dept" name="dept">
      <option value="">All departments</option>
      <?php foreach (departments() as $d): ?>
        <option value="<?= e($d) ?>"<?= $dept === $d ? ' selected' : '' ?>><?= e($d) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endif; ?>
  <div class="form-group">
    <label for="sort">Sort by</label>
    <select id="sort" name="sort">
      <option value=""<?= $sort === '' ? ' selected' : '' ?>>Status, then newest update</option>
      <option value="newest"<?= $sort === 'newest' ? ' selected' : '' ?>>Newest registered</option>
      <option value="oldest"<?= $sort === 'oldest' ? ' selected' : '' ?>>Oldest updated</option>
      <option value="priority"<?= $sort === 'priority' ? ' selected' : '' ?>>Priority (critical first)</option>
    </select>
  </div>
  <div class="form-group">
    <button class="btn btn-primary" type="submit">Filter</button>
    <a class="btn" href="<?= BASE_URL ?>/tickets.php">Reset</a>
  </div>
</form>

<div class="card">
  <?php if (!$tickets): ?>
    <div class="empty">
      <h3>Nothing to show</h3>
      <p>No tickets match the current filters. Try a different status chip, clear the search box,
         or register a new problem.</p>
      <a class="btn" href="<?= BASE_URL ?>/tickets.php">Clear all filters</a>
    </div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Ticket</th><th>Title</th><th>Reporter</th><th>Category</th><th>Team</th>
          <th>Priority</th><th>Status</th><th>Assignee</th><th>Updated</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($slice as $t): ?>
        <tr>
          <td><a href="<?= BASE_URL ?>/ticket-view.php?id=<?= (int)$t['id'] ?>"><?= e($t['ticket_no']) ?></a></td>
          <td><?= e($t['title']) ?></td>
          <td class="muted"><?= e($t['reporter']) ?></td>
          <td class="muted"><?= e($t['category']) ?></td>
          <td class="muted"><?= e($t['handling_department'] ?? '—') ?></td>
          <td><?= priority_badge($t['priority']) ?></td>
          <td><?= status_badge($t['status']) ?></td>
          <td class="muted"><?= $t['assignee'] ? e($t['assignee']) : '—' ?></td>
          <td class="muted small"><?= date('d M Y H:i', strtotime($t['updated_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
    <div class="pager">
      <span class="muted small">Page <?= $page ?> of <?= $pages ?></span>
      <span class="pages">
        <?php if ($page > 1): ?>
          <a href="<?= e(tqs(['page' => $page - 1])) ?>">&larr; Previous</a>
        <?php endif; ?>
        <?php
        $start = max(1, $page - 3);
        $end   = min($pages, $page + 3);
        if ($start > 1): ?><span>…</span><?php endif;
        for ($i = $start; $i <= $end; $i++): ?>
          <?php if ($i === $page): ?>
            <span class="on"><?= $i ?></span>
          <?php else: ?>
            <a href="<?= e(tqs(['page' => $i])) ?>"><?= $i ?></a>
          <?php endif; ?>
        <?php endfor;
        if ($end < $pages): ?><span>…</span><?php endif; ?>
        <?php if ($page < $pages): ?>
          <a href="<?= e(tqs(['page' => $page + 1])) ?>">Next &rarr;</a>
        <?php endif; ?>
      </span>
    </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
