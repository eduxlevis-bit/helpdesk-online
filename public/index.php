<?php
require_once __DIR__ . '/../includes/helpers.php';
requireRole(['admin', 'tech', 'user']);

$db = getDB();
$me = currentUser();

/* ── Stats by role ────────────────────────────────────────────────────────── */
if ($me['role'] === 'admin') {
    $stats = $db->query(
        "SELECT COUNT(*) total,
                SUM(status IN ('Open','Assigned','In Progress')) active,
                SUM(assigned_to IS NULL AND status = 'Open') unassigned,
                SUM(status = 'Resolved') resolved
         FROM tickets"
    )->fetch();
} elseif ($me['role'] === 'tech') {
    $stmt = $db->prepare(
        "SELECT COUNT(*) total,
                SUM(assigned_to = ? AND status IN ('Open','Assigned','In Progress')) mine,
                SUM(handling_department = ? AND status IN ('Open','Assigned','In Progress')) team,
                SUM(handling_department = ? AND assigned_to IS NULL AND status = 'Open') unassigned,
                SUM(handling_department = ? AND status = 'Resolved') resolved
         FROM tickets
         WHERE user_id = ? OR assigned_to = ? OR handling_department = ?"
    );
    $stmt->execute([
        $me['id'], $me['department'], $me['department'], $me['department'],
        $me['id'], $me['id'], $me['department'],
    ]);
    $stats = $stmt->fetch();
} else {
    $stmt = $db->prepare(
        "SELECT COUNT(*) total,
                SUM(status IN ('Open','Assigned','In Progress')) active,
                SUM(status = 'Resolved') resolved,
                SUM(status = 'Closed') closed
         FROM tickets WHERE user_id = ?"
    );
    $stmt->execute([$me['id']]);
    $stats = $stmt->fetch();
}
$stats = array_map(function ($v) { return (int)($v ?? 0); }, $stats);

/* ── Charts (scoped to what this user may see) ────────────────────────────── */
list($visSql, $visParams) = visible_tickets_sql('t');

$byStatus = array_fill_keys(STATUSES, 0);
$q = $db->prepare("SELECT t.status, COUNT(*) c FROM tickets t WHERE 1=1 {$visSql} GROUP BY t.status");
$q->execute($visParams);
foreach ($q->fetchAll() as $r) {
    $byStatus[$r['status']] = (int)$r['c'];
}
$statusTotal = max(1, array_sum($byStatus));

$q = $db->prepare("SELECT t.category, COUNT(*) c FROM tickets t WHERE 1=1 {$visSql}
                   GROUP BY t.category ORDER BY c DESC, t.category ASC LIMIT 6");
$q->execute($visParams);
$byCat   = $q->fetchAll();
$catMax  = max(1, max(array_map(function ($r) { return (int)$r['c']; }, $byCat ?: [['c' => 1]])));

// Desk health: share of tickets that are resolved/closed
$totalTickets   = max(1, array_sum($byStatus));
$resolvedTickets = ($byStatus['Resolved'] ?? 0) + ($byStatus['Closed'] ?? 0);
$healthPercent  = (int)round($resolvedTickets / $totalTickets * 100);
$healthColor    = $healthPercent >= 70 ? '#16a34a' : ($healthPercent >= 40 ? '#f59e0b' : '#e01b24');
$healthLabel    = $healthPercent >= 70 ? 'Healthy' : ($healthPercent >= 40 ? 'Busy' : 'Backlogged');

// Count urgent items for THIS user's view (Critical + unassigned, scoped by role)
$urgentStmt = $db->prepare("SELECT COUNT(*) FROM tickets t
                             WHERE 1=1 {$visSql}
                               AND t.priority = 'Critical'
                               AND t.status IN ('Open','Assigned','In Progress')");
$urgentStmt->execute($visParams);
$urgentCount = (int)$urgentStmt->fetchColumn();

$unassignedStmt = $db->prepare("SELECT COUNT(*) FROM tickets t
                                 WHERE 1=1 {$visSql}
                                   AND t.assigned_to IS NULL AND t.status = 'Open'");
$unassignedStmt->execute($visParams);
$unassignedCount = (int)$unassignedStmt->fetchColumn();

$q = $db->prepare("SELECT DATE(t.created_at) d, COUNT(*) c FROM tickets t
                    WHERE 1=1 {$visSql} AND t.created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
                   GROUP BY DATE(t.created_at)");
$q->execute($visParams);
$byDay = [];
foreach ($q->fetchAll() as $r) {
    $byDay[date('Y-m-d', strtotime($r['d']))] = (int)$r['c'];
}
$days   = [];
for ($i = 13; $i >= 0; $i--) {
    $key = date('Y-m-d', strtotime("-{$i} days"));
    $days[$key] = $byDay[$key] ?? 0;
}
$dayMax = max(1, max($days));

/* ── Recent tickets visible to this user ──────────────────────────────────── */
$recent = $db->prepare(
    "SELECT t.*, u.name reporter, a.name assignee
       FROM tickets t
       JOIN users u ON u.id = t.user_id
  LEFT JOIN users a ON a.id = t.assigned_to
      WHERE 1=1 {$visSql}
   ORDER BY t.updated_at DESC LIMIT 8"
);
$recent->execute($visParams);
$recent = $recent->fetchAll();

/* ── Activity feed: newest replies + newest tickets ───────────────────────── */
$openedFeed = $db->prepare(
    "SELECT t.id, t.ticket_no, t.title, u.name actor, 'opened' what, t.created_at at
       FROM tickets t JOIN users u ON u.id = t.user_id
      WHERE 1=1 {$visSql} ORDER BY t.created_at DESC LIMIT 6"
);
$openedFeed->execute($visParams);

$replyFeed = $db->prepare(
    "SELECT t.id, t.ticket_no, t.title, u.name actor, 'replied' what, r.created_at at
       FROM ticket_replies r
       JOIN tickets t ON t.id = r.ticket_id
       JOIN users u  ON u.id = r.user_id
      WHERE 1=1 {$visSql} ORDER BY r.created_at DESC LIMIT 6"
);
$replyFeed->execute($visParams);

$feed = array_merge($openedFeed->fetchAll(), $replyFeed->fetchAll());
usort($feed, function ($a, $b) { return strtotime($b['at']) <=> strtotime($a['at']); });
$feed = array_slice($feed, 0, 8);

$pageTitle = 'Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <div>
    <h1>Hello, <?= e(explode(' ', $me['name'])[0]) ?></h1>
    <p class="muted"><?= $me['role'] === 'tech'
        ? 'Tickets routed to <strong>' . e($me['department'] ?: 'your team') . '</strong> plus anything assigned to you.'
        : 'Here is the current state of the helpdesk.' ?> · <?= date('l, d M Y') ?></p>
  </div>
  <a class="btn btn-primary" href="<?= BASE_URL ?>/ticket-new.php">+ Register a problem</a>
</div>

<div class="grid grid-4" style="margin-bottom:22px">
  <?php if ($me['role'] === 'admin'): ?>
    <div class="stat"><div class="label">Total tickets</div><div class="value"><?= $stats['total'] ?></div></div>
    <div class="stat"><div class="label">Active</div><div class="value"><?= $stats['active'] ?></div><div class="sub">Open, assigned or in progress</div></div>
    <div class="stat"><div class="label">Unassigned</div><div class="value"><?= $stats['unassigned'] ?></div><div class="sub">Awaiting an assignee</div></div>
    <div class="stat"><div class="label">Resolved</div><div class="value"><?= $stats['resolved'] ?></div></div>
  <?php elseif ($me['role'] === 'tech'): ?>
    <div class="stat"><div class="label">Assigned to me</div><div class="value"><?= $stats['mine'] ?></div></div>
    <div class="stat"><div class="label"><?= e($me['department'] ?: 'My department') ?></div><div class="value"><?= $stats['team'] ?></div><div class="sub">Open tickets for my team</div></div>
    <div class="stat"><div class="label">Unassigned queue</div><div class="value"><?= $stats['unassigned'] ?></div><div class="sub">Nobody picked these up</div></div>
    <div class="stat"><div class="label">Resolved (team)</div><div class="value"><?= $stats['resolved'] ?></div></div>
  <?php else: ?>
    <div class="stat"><div class="label">My tickets</div><div class="value"><?= $stats['total'] ?></div></div>
    <div class="stat"><div class="label">Active</div><div class="value"><?= $stats['active'] ?></div></div>
    <div class="stat"><div class="label">Resolved</div><div class="value"><?= $stats['resolved'] ?></div></div>
    <div class="stat"><div class="label">Closed</div><div class="value"><?= $stats['closed'] ?></div></div>
  <?php endif; ?>
</div>

<div class="grid grid-2" style="margin-bottom:22px">
  <div class="card">
    <div class="card-head">
      <h2>Desk health</h2>
      <span class="badge <?= $healthPercent >= 70 ? 'badge-on' : ($healthPercent >= 40 ? 'badge-open' : 'badge-off') ?>">
        <?= $healthLabel ?>
      </span>
    </div>
    <div class="bar-row" style="grid-template-columns:92px 1fr 52px">
      <span class="bar-label">Resolved</span>
      <span class="bar-track">
        <span class="bar-fill" style="width: <?= $healthPercent ?>%;background:<?= $healthColor ?>;transition:width .4s"></span>
      </span>
      <span class="bar-val"><?= $healthPercent ?>%</span>
    </div>
    <p class="muted small" style="margin:10px 0 0">
      <?= $resolvedTickets ?> of <?= $totalTickets ?> ticket(s) are resolved or closed.
      <?= $healthPercent >= 70 ? 'Nice work keeping the queue down.' : 'New tickets are arriving faster than they are being closed.' ?>
    </p>
  </div>

  <div class="card">
    <div class="card-head">
      <h2>Needs attention</h2>
      <span class="muted">from your dashboard</span>
    </div>
    <?php if ($urgentCount === 0 && $unassignedCount === 0): ?>
      <div class="empty" style="padding:22px"><p>Nothing urgent right now — no critical tickets and nothing waiting for an assignee.</p></div>
    <?php else: ?>
      <ul class="feed">
        <?php if ($urgentCount > 0): ?>
          <li>
            <span class="avatar" style="background:#fdecec;color:#c0272d"><?= $urgentCount ?></span>
            <span class="txt">
              <strong><?= $urgentCount ?></strong> critical ticket<?= $urgentCount === 1 ? '' : 's' ?> still active<br>
              <a href="<?= BASE_URL ?>/tickets.php?sort=priority">Work them first &rarr;</a>
            </span>
          </li>
        <?php endif; ?>
        <?php if ($unassignedCount > 0): ?>
          <li>
            <span class="avatar" style="background:#fff4e5;color:#b54708"><?= $unassignedCount ?></span>
            <span class="txt">
              <strong><?= $unassignedCount ?></strong> ticket<?= $unassignedCount === 1 ? '' : 's' ?> still unassigned<br>
              <a href="<?= BASE_URL ?>/tickets.php?status=Open">Open the queue &rarr;</a>
            </span>
          </li>
        <?php endif; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<div class="grid grid-2" style="margin-bottom:22px">
  <div class="card">
    <div class="card-head">
      <h2>Tickets by status</h2>
      <span class="muted"><?= array_sum($byStatus) ?> in total</span>
    </div>
    <?php foreach ($byStatus as $statusName => $count): ?>
      <div class="bar-row">
        <span class="bar-label"><?= e($statusName) ?></span>
        <span class="bar-track">
          <span class="bar-fill f-<?= e(str_replace(' ', '-', $statusName)) ?>"
                style="width: <?= (int)round($count / $statusTotal * 100) ?>%"></span>
        </span>
        <span class="bar-val"><?= $count ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <div class="card-head">
      <h2>By category</h2>
      <span class="muted">busiest first</span>
    </div>
    <?php if (!$byCat): ?>
      <div class="empty" style="padding:22px"><p>No tickets yet.</p></div>
    <?php else: ?>
      <?php foreach ($byCat as $c): ?>
        <div class="bar-row">
          <span class="bar-label"><?= e($c['category']) ?></span>
          <span class="bar-track">
            <span class="bar-fill" style="width: <?= (int)round((int)$c['c'] / $catMax * 100) ?>%"></span>
          </span>
          <span class="bar-val"><?= (int)$c['c'] ?></span>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<div class="dash-split">
  <div class="card" style="margin-bottom:0">
    <h2><?= $me['role'] === 'user' ? 'My recent tickets' : 'Recent tickets' ?></h2>
    <?php if (!$recent): ?>
      <div class="empty">
        <h3>No tickets yet</h3>
        <p>Nothing has been raised so far. When someone registers a problem it lands here with its status, team and assignee.</p>
        <a class="btn btn-primary" href="<?= BASE_URL ?>/ticket-new.php">Register the first problem</a>
      </div>
    <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Ticket</th><th>Title</th><th>Category</th><th>Team</th><th>Priority</th>
            <th>Status</th><th>Assignee</th><th>Updated</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($recent as $t): ?>
          <tr>
            <td><a href="<?= BASE_URL ?>/ticket-view.php?id=<?= (int)$t['id'] ?>"><?= e($t['ticket_no']) ?></a></td>
            <td><?= e($t['title']) ?></td>
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
    <p class="muted small" style="margin:12px 0 0">
      <a href="<?= BASE_URL ?>/tickets.php">See all tickets &rarr;</a>
    </p>
    <?php endif; ?>
  </div>

  <div class="dash-side">
    <div class="card" style="margin-bottom:0">
      <div class="card-head">
        <h2>Last 14 days</h2>
        <span class="muted">new tickets</span>
      </div>
      <div class="cols">
        <?php foreach ($days as $day => $count): ?>
          <div class="col">
            <b><?= $count ?: '' ?></b>
            <i style="height: <?= max(3, (int)round($count / $dayMax * 84)) ?>px"></i>
            <span><?= date('d', strtotime($day)) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <p class="cols-legend"><?= array_sum($days) ?> ticket(s) raised in the last 14 days.</p>
    </div>

    <div class="card" style="margin-bottom:0">
      <div class="card-head">
        <h2>Latest activity</h2>
        <span class="muted">replies &amp; new tickets</span>
      </div>
      <?php if (!$feed): ?>
        <div class="empty" style="padding:22px"><p>Activity will appear here as soon as tickets and replies start coming in.</p></div>
      <?php else: ?>
        <ul class="feed">
          <?php foreach ($feed as $f): ?>
            <li>
              <span class="avatar"><?= e(initials($f['actor'])) ?></span>
              <span class="txt">
                <strong><?= e($f['actor']) ?></strong>
                <?= $f['what'] === 'replied' ? 'replied on' : 'raised' ?>
                <a href="<?= BASE_URL ?>/ticket-view.php?id=<?= (int)$f['id'] ?>"><?= e($f['ticket_no']) ?></a><br>
                <span class="muted"><?= e(mb_substr($f['title'], 0, 60)) ?></span>
                <span class="when"><?= e(time_ago($f['at'])) ?></span>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
