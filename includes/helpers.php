<?php
require_once __DIR__ . '/auth.php';

function role_label(string $role): string {
    return ROLES[$role] ?? $role;
}

function status_badge(string $status): string {
    $map = ['Open' => 'open', 'Assigned' => 'assigned', 'In Progress' => 'progress',
            'Resolved' => 'resolved', 'Closed' => 'closed'];
    $cls = $map[$status] ?? 'open';
    return '<span class="badge badge-' . $cls . '">' . e($status) . '</span>';
}

function priority_badge(string $priority): string {
    $map = ['Low' => 'low', 'Medium' => 'medium', 'High' => 'high', 'Critical' => 'critical'];
    $cls = $map[$priority] ?? 'medium';
    return '<span class="badge badge-p-' . $cls . '">' . e($priority) . '</span>';
}

function email_status_badge(string $status): string {
    $map = ['sent' => ['badge-on', 'Sent'], 'failed' => ['badge-off', 'Failed'],
            'logged' => ['badge-open', 'Logged']];
    $m = $map[$status] ?? ['badge-open', e($status)];
    return '<span class="badge ' . $m[0] . '">' . e($m[1]) . '</span>';
}

/** Optional phone numbers: 5-30 chars, digits and + ( ) . - and spaces. */
function valid_phone(string $phone): bool {
    if ($phone === '') return true;
    return (bool)preg_match('/^[0-9+()\s.\-]{5,30}$/', $phone);
}

/**
 * Password policy: 8+ characters, at least one letter and one number,
 * and it must not simply be the user's email or name.
 */
function password_policy_errors(string $password, string $email = '', string $name = ''): array {
    $errors = [];
    if (strlen($password) < 8)          $errors[] = 'Password must be at least 8 characters.';
    if (!preg_match('/[A-Za-z]/', $password)) $errors[] = 'Password must contain at least one letter.';
    if (!preg_match('/[0-9]/', $password))    $errors[] = 'Password must contain at least one number.';
    if ($email !== '' && strtolower($password) === strtolower($email)) {
        $errors[] = 'Password cannot be your email address.';
    }
    if ($name !== '' && $password !== '' && strcasecmp($password, $name) === 0) {
        $errors[] = 'Password cannot be your name.';
    }
    return $errors;
}

/** Strength score 0-4 used by the password meter (mirrored in js/password.js). */
function password_score(string $password): int {
    if ($password === '') return 0;
    $score = 0;
    if (strlen($password) >= 8)  $score++;
    if (strlen($password) >= 12) $score++;
    if (preg_match('/[A-Za-z]/', $password) && preg_match('/[0-9]/', $password)) $score++;
    if (preg_match('/[^A-Za-z0-9]/', $password)) $score++;
    if (preg_match('/^[A-Za-z]+$/', $password) || preg_match('/^[0-9]+$/', $password)) {
        $score = min($score, 1);
    }
    return min($score, 4);
}

/* ── Categories (each one routes to a department) ──────────────────────────── */
/** "3 hours ago" — used by the dashboard activity feed and ticket ages. */
function time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' h ago';
    $days = (int)floor($diff / 86400);
    if ($days < 30)    return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    return date('d M Y', strtotime($datetime));
}

function categories(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $cache = getDB()->query(
            'SELECT id, name, department FROM categories WHERE active = 1 ORDER BY name'
        )->fetchAll();
    } catch (PDOException $e) {
        $cache = [];
    }
    return $cache;
}

function categoryById(int $id): ?array {
    foreach (categories() as $c) {
        if ((int)$c['id'] === $id) return $c;
    }
    return null;
}

function departments(): array {
    $list = [];
    foreach (categories() as $c) {
        if ($c['department'] !== '' && !in_array($c['department'], $list, true)) {
            $list[] = $c['department'];
        }
    }
    try {
        $rows = getDB()->query('SELECT DISTINCT department FROM users
                                 WHERE department IS NOT NULL AND department <> ""')->fetchAll();
        foreach ($rows as $r) {
            if (!in_array($r['department'], $list, true)) $list[] = $r['department'];
        }
    } catch (PDOException $e) {
        // ignore — list still holds category departments
    }
    sort($list);
    return $list;
}

/* ── Ticket visibility ─────────────────────────────────────────────────────── */
function ticketTeamSql(string $t = 't'): string {
    return " COALESCE({$t}.handling_department, '') = ?";
}

/**
 * SQL fragment + bound params limiting tickets to what the current user may see.
 * admin: everything | user: own tickets | tech: own, assigned, or own department
 */
function visible_tickets_sql(string $t = 't'): array {
    $u = currentUser();
    if ($u['role'] === 'admin') return ['', []];
    if ($u['role'] === 'user')  return [" AND {$t}.user_id = ?", [$u['id']]];
    return [
        " AND ({$t}.user_id = ? OR {$t}.assigned_to = ? OR {$t}.handling_department = ?)",
        [$u['id'], $u['id'], $u['department']],
    ];
}

function canViewTicket(array $ticket): bool {
    $u = currentUser();
    if ($u['role'] === 'admin') return true;
    if ((int)$ticket['user_id'] === (int)$u['id']) return true;
    if (!empty($ticket['assigned_to']) && (int)$ticket['assigned_to'] === (int)$u['id']) return true;
    if ($u['role'] === 'tech'
        && $u['department'] !== ''
        && ($ticket['handling_department'] ?? '') === $u['department']) {
        return true;
    }
    return false;
}

function canAssign(array $ticket): bool {
    return isAdmin();
}

function canUpdateStatus(array $ticket): bool {
    $u = currentUser();
    if ($u['role'] === 'admin') return true;
    if (!empty($ticket['assigned_to']) && (int)$ticket['assigned_to'] === (int)$u['id']) return true;
    if ($u['role'] === 'tech'
        && $u['department'] !== ''
        && ($ticket['handling_department'] ?? '') === $u['department']) {
        return true;
    }
    return false;
}

/** Technicians that may take a ticket: same department first, otherwise all. */
function assignableTechs(array $ticket): array {
    $db = getDB();
    $team = $ticket['handling_department'] ?? '';
    if ($team !== '') {
        $stmt = $db->prepare(
            "SELECT id, name, department FROM users
              WHERE role = 'tech' AND active = 1 AND department = ? ORDER BY name"
        );
        $stmt->execute([$team]);
        $rows = $stmt->fetchAll();
        if ($rows) return $rows;
    }
    return $db->query(
        "SELECT id, name, department FROM users WHERE role = 'tech' AND active = 1 ORDER BY name"
    )->fetchAll();
}

function next_ticket_no(PDO $db): string {
    $year  = date('Y');
    $like  = TICKET_PREFIX . '-' . $year . '-%';
    $stmt  = $db->prepare("SELECT COUNT(*) FROM tickets WHERE ticket_no LIKE ?");
    $stmt->execute([$like]);
    $seq = (int)$stmt->fetchColumn() + 1;
    do {
        $no   = TICKET_PREFIX . '-' . $year . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
        $chk  = $db->prepare('SELECT COUNT(*) FROM tickets WHERE ticket_no = ?');
        $chk->execute([$no]);
        $seq++;
    } while ((int)$chk->fetchColumn() > 0);
    return $no;
}
