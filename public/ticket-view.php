<?php
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/notify.php';
requireLogin();

$db = getDB();
$me = currentUser();

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare(
    "SELECT t.*, u.name reporter, u.email reporter_email, u.department reporter_dept,
            a.name assignee, a.email assignee_email, a.phone assignee_phone
       FROM tickets t
       JOIN users u ON u.id = t.user_id
  LEFT JOIN users a ON a.id = t.assigned_to
      WHERE t.id = ?"
);
$stmt->execute([$id]);
$ticket = $stmt->fetch();

if (!$ticket || !canViewTicket($ticket)) {
    http_response_code(404);
    $pageTitle = 'Not found';
    require __DIR__ . '/../includes/header.php';
    echo '<div class="empty">Ticket not found or you do not have access to it.</div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'reply') {
        $body = trim($_POST['body'] ?? '');
        if ($body !== '') {
            $stmt = $db->prepare('INSERT INTO ticket_replies (ticket_id, user_id, body) VALUES (?, ?, ?)');
            $stmt->execute([$ticket['id'], $me['id'], $body]);
            if ($ticket['status'] === 'Open' && (int)$ticket['assigned_to'] === (int)$me['id']) {
                $db->prepare("UPDATE tickets SET status = 'Assigned' WHERE id = ?")->execute([$ticket['id']]);
                $ticket['status'] = 'Assigned';
            }
            notify_ticket_replied($ticket, $me, $body);
            setFlash('success', 'Reply posted.');
        }
    }

    if ($action === 'assign' && canAssign($ticket)) {
        $techId = (int)($_POST['assigned_to'] ?? 0);
        if ($techId === 0) {
            $db->prepare('UPDATE tickets SET assigned_to = NULL, status = ? WHERE id = ?')
               ->execute([$ticket['status'] === 'Open' ? 'Open' : $ticket['status'], $ticket['id']]);
            setFlash('info', 'Assignment removed.');
        } else {
            $t = $db->prepare("SELECT id, name, email, phone, department FROM users WHERE id = ? AND role = 'tech' AND active = 1");
            $t->execute([$techId]);
            $tech = $t->fetch();
            if ($tech) {
                $newStatus = in_array($ticket['status'], ['Open', 'Resolved'], true)
                    ? ($ticket['status'] === 'Resolved' ? 'Resolved' : 'Assigned')
                    : $ticket['status'];
                $db->prepare('UPDATE tickets SET assigned_to = ?, status = ? WHERE id = ?')
                   ->execute([$techId, $newStatus, $ticket['id']]);
                $ticket['assigned_to'] = $techId;
                $ticket['status']      = $newStatus;
                $ticket['assignee']    = $tech['name'];
                $ticket['assignee_email'] = $tech['email'];
                notify_ticket_assigned($ticket, $tech, $me);
                if ($tech['department'] !== '' && $tech['department'] !== ($ticket['handling_department'] ?? '')) {
                    setFlash('info', 'Assigned to ' . $tech['name'] . ' (' . $tech['department']
                        . ') — outside the ' . ($ticket['handling_department'] ?: 'handling') . ' team.');
                } else {
                    setFlash('success', 'Ticket assigned to ' . $tech['name'] . '.');
                }
            } else {
                setFlash('error', 'Selected technician is not available.');
            }
        }
    }

    if ($action === 'status' && canUpdateStatus($ticket)) {
        $status = $_POST['status'] ?? '';
        if (in_array($status, STATUSES, true) && $status !== $ticket['status']) {
            $oldStatus = $ticket['status'];
            $db->prepare('UPDATE tickets SET status = ? WHERE id = ?')->execute([$status, $ticket['id']]);
            $ticket['status'] = $status;
            notify_status_changed($ticket, $oldStatus, $status, $me);
            setFlash('success', 'Status updated to ' . $status . '.');
        } elseif ($status === $ticket['status']) {
            setFlash('info', 'Status is already "' . $status . '".');
        }
    }

    header('Location: ' . BASE_URL . '/ticket-view.php?id=' . $ticket['id']);
    exit;
}

$replies = $db->prepare(
    'SELECT r.*, u.name, u.role FROM ticket_replies r JOIN users u ON u.id = r.user_id
      WHERE r.ticket_id = ? ORDER BY r.created_at ASC'
);
$replies->execute([$ticket['id']]);
$replies = $replies->fetchAll();

$canSeeEmails = currentUser()['role'] !== 'user';   // log is for admins + technicians
$emails = [];
if ($canSeeEmails) {
    $emailStmt = $db->prepare(
        'SELECT * FROM ticket_emails WHERE ticket_id = ? ORDER BY created_at DESC, id DESC'
    );
    $emailStmt->execute([$ticket['id']]);
    $emails = $emailStmt->fetchAll();
}

$techs = assignableTechs($ticket);
$teamMatch = $ticket['handling_department'] !== ''
    && array_filter($techs, function ($t) use ($ticket) { return $t['department'] === $ticket['handling_department']; });

$pageTitle = $ticket['ticket_no'];
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
  <div>
    <h1><?= e($ticket['ticket_no']) ?></h1>
    <p class="muted"><?= e($ticket['title']) ?></p>
  </div>
  <a class="btn" href="<?= BASE_URL ?>/tickets.php">&larr; Back to tickets</a>
</div>

<div class="grid grid-2">
  <div class="card">
    <h2>Details</h2>
    <?php
    $steps    = STATUSES;
    $stepIdx  = array_search($ticket['status'], $steps, true);
    $stepIdx  = $stepIdx === false ? 0 : $stepIdx;
    $openDays = (int)floor((time() - strtotime($ticket['created_at'])) / 86400);
    $stillOpen = !in_array($ticket['status'], ['Resolved', 'Closed'], true);
    $ageCls   = !$stillOpen ? '' : ($openDays >= 3 ? 'hot' : ($openDays >= 1 ? 'warn' : ''));
    ?>
    <ol class="timeline">
      <?php foreach ($steps as $i => $s): ?>
        <li class="<?= $i < $stepIdx ? 'done' : ($i === $stepIdx ? 'now' : '') ?>"><?= e($s) ?></li>
      <?php endforeach; ?>
    </ol>
    <dl class="kv">
      <dt>Status</dt><dd><?= status_badge($ticket['status']) ?></dd>
      <dt>Priority</dt><dd><?= priority_badge($ticket['priority']) ?></dd>
      <dt>Category</dt><dd><?= e($ticket['category']) ?></dd>
      <dt>Handling team</dt>
      <dd><?= $ticket['handling_department'] ? e($ticket['handling_department'])
            : '<span class="muted">Not routed</span>' ?></dd>
      <dt>Reporter</dt><dd><?= e($ticket['reporter']) ?> <span class="muted small">(<?= e($ticket['reporter_dept'] ?: $ticket['reporter_email']) ?>)</span></dd>
      <dt>Assignee</dt>
      <dd>
        <?php if ($ticket['assignee']): ?>
          <?= e($ticket['assignee']) ?><br>
          <span class="muted small">
            <a href="mailto:<?= e($ticket['assignee_email']) ?>"><?= e($ticket['assignee_email']) ?></a>
            <?php if (!empty($ticket['assignee_phone'])): ?>
              · <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $ticket['assignee_phone'])) ?>"><?= e($ticket['assignee_phone']) ?></a>
            <?php endif; ?>
          </span>
        <?php else: ?>
          <span class="muted">Unassigned</span>
        <?php endif; ?>
      </dd>
      <dt>Created</dt>
      <dd><?= date('d M Y, H:i', strtotime($ticket['created_at'])) ?>
        <span class="muted small"><?= e(time_ago($ticket['created_at'])) ?></span></dd>
      <dt><?= $stillOpen ? 'Open for' : 'Handled in' ?></dt>
      <dd><span class="aging <?= $ageCls ?>"><?= $openDays === 0
            ? ($stillOpen ? 'less than a day' : 'same day')
            : $openDays . ' day' . ($openDays === 1 ? '' : 's') ?></span></dd>
      <dt>Updated</dt>
      <dd><?= date('d M Y, H:i', strtotime($ticket['updated_at'])) ?>
        <span class="muted small"><?= e(time_ago($ticket['updated_at'])) ?></span></dd>
    </dl>

    <h2 style="margin-top:20px">Description</h2>
    <div class="desc-block"><?= e($ticket['description']) ?></div>
  </div>

  <div>
    <?php if (canAssign($ticket)): ?>
    <div class="card">
      <h2>Assignment <span class="muted small">(admin)</span></h2>
      <form method="post" action="<?= BASE_URL ?>/ticket-view.php?id=<?= (int)$ticket['id'] ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="assign">
        <div class="form-group">
          <label for="assigned_to">Assign to technician</label>
          <select id="assigned_to" name="assigned_to">
            <option value="0">— Unassigned —</option>
            <?php foreach ($techs as $tech): ?>
              <option value="<?= (int)$tech['id'] ?>"<?= (int)$ticket['assigned_to'] === (int)$tech['id'] ? ' selected' : '' ?>>
                <?= e($tech['name']) ?><?= $tech['department'] ? ' (' . e($tech['department']) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (!$teamMatch): ?>
            <div class="hint">No active technician in <?= e($ticket['handling_department'] ?: 'this team') ?>
              — showing every technician.</div>
          <?php else: ?>
            <div class="hint">Technicians from the <?= e($ticket['handling_department']) ?> team.</div>
          <?php endif; ?>
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Save assignment</button>
      </form>
    </div>
    <?php endif; ?>

    <?php if (canUpdateStatus($ticket)): ?>
    <div class="card">
      <h2>Update status</h2>
      <form method="post" action="<?= BASE_URL ?>/ticket-view.php?id=<?= (int)$ticket['id'] ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="status">
        <div class="form-group">
          <label for="status">Status</label>
          <select id="status" name="status">
            <?php foreach (STATUSES as $s): ?>
              <option value="<?= e($s) ?>"<?= $ticket['status'] === $s ? ' selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-primary btn-sm" type="submit">Update status</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>Conversation (<?= count($replies) ?>)</h2>
  <?php if (!$replies): ?>
    <div class="empty">No replies yet.</div>
  <?php else: ?>
    <?php foreach ($replies as $r): ?>
      <div class="reply">
        <div class="reply-head">
          <strong><?= e($r['name']) ?></strong>
          <span class="muted"><?= e(role_label($r['role'])) ?> · <?= date('d M Y, H:i', strtotime($r['created_at'])) ?></span>
        </div>
        <div class="reply-body"><?= e($r['body']) ?></div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <form method="post" action="<?= BASE_URL ?>/ticket-view.php?id=<?= (int)$ticket['id'] ?>" style="margin-top:14px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reply">
    <div class="form-group">
      <label for="body">Add a reply</label>
      <textarea id="body" name="body" required placeholder="Type your message, troubleshooting steps or resolution notes..." style="min-height:100px"></textarea>
    </div>
    <button class="btn btn-primary" type="submit">Post reply</button>
  </form>
</div>

<?php if ($canSeeEmails): ?>
<div class="card">
  <h2>Email notifications (<?= count($emails) ?>)</h2>
  <p class="muted small" style="margin-bottom:12px">
    Every update mailed to the people involved in this ticket.
  </p>
  <?php if (!$emails): ?>
    <div class="empty">No emails have been sent for this ticket yet.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Sent</th><th>Event</th><th>To</th><th>Subject</th><th>Status</th></tr>
      </thead>
      <tbody>
      <?php foreach ($emails as $mail): ?>
        <tr>
          <td class="muted small"><?= date('d M Y, H:i', strtotime($mail['created_at'])) ?></td>
          <td><?= e(NOTIFY_EVENTS[$mail['event']] ?? $mail['event']) ?></td>
          <td>
            <?= e($mail['recipient_name'] ?: $mail['recipient']) ?>
            <br><span class="muted small"><?= e($mail['recipient']) ?></span>
          </td>
          <td><?= e($mail['subject']) ?></td>
          <td><?= email_status_badge($mail['status']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
