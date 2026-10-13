<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

if (session_name() !== SESSION_NAME) {
    session_name(SESSION_NAME);
}
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Idle timeout — 30 minutes normally, 12 hours when signed in via the remember-me cookie
$timeout = !empty($_SESSION['remembered']) ? 43200 : IDLE_TIMEOUT;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

/* ── Remember-me (30 days) ─────────────────────────────────────────────────── */
const REMEMBER_COOKIE = 'hd_remember';
const REMEMBER_DAYS   = 30;

function issueRememberToken(int $userId): void {
    $token = bin2hex(random_bytes(24));
    // Expiry is computed by MySQL so it always matches NOW() in the cookie check
    getDB()->prepare(
        'UPDATE users SET remember_token = ?,
                remember_expires = DATE_ADD(NOW(), INTERVAL ' . REMEMBER_DAYS . ' DAY)
          WHERE id = ?'
    )->execute([hash('sha256', $token), $userId]);
    setcookie(REMEMBER_COOKIE, $userId . '.' . $token, [
        'expires'  => time() + REMEMBER_DAYS * 86400,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
}

function clearRememberToken(?int $userId = null): void {
    if ($userId) {
        try {
            getDB()->prepare('UPDATE users SET remember_token = NULL, remember_expires = NULL WHERE id = ?')
                ->execute([$userId]);
        } catch (Throwable $e) { /* ignore */ }
    }
    setcookie(REMEMBER_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
    unset($_COOKIE[REMEMBER_COOKIE]);
}

/** Sign the visitor in from a valid remember-me cookie (silent no-op otherwise). */
function loginFromRememberCookie(): void {
    if (empty($_COOKIE[REMEMBER_COOKIE])) return;
    $parts = explode('.', $_COOKIE[REMEMBER_COOKIE], 2);
    if (count($parts) !== 2 || !ctype_digit($parts[0])) return;

    try {
        $st = getDB()->prepare(
            "SELECT id, name, email, role, department FROM users
              WHERE id = ? AND remember_token = ? AND remember_expires > NOW() AND active = 1"
        );
        $st->execute([(int)$parts[0], hash('sha256', $parts[1])]);
        $user = $st->fetch();
    } catch (Throwable $e) {
        return;
    }
    if (!$user) return;

    session_regenerate_id(true);
    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['user_dept']  = $user['department'] ?? '';
    $_SESSION['remembered'] = true;
    $_SESSION['last_activity'] = time();
}

if (empty($_SESSION['user_id'])) {
    loginFromRememberCookie();
}

function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

function currentUser(): array {
    return [
        'id'         => $_SESSION['user_id']    ?? 0,
        'name'       => $_SESSION['user_name']  ?? '',
        'email'      => $_SESSION['user_email'] ?? '',
        'role'       => $_SESSION['user_role']  ?? '',
        'department' => $_SESSION['user_dept']  ?? '',
    ];
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
}

function requireRole(array $roles): void {
    requireLogin();
    if (!in_array(currentUser()['role'], $roles, true)) {
        http_response_code(403);
        echo '<h1>403 — Access denied</h1><p>You do not have permission to view this page.</p>'
           . '<p><a href="' . BASE_URL . '/index.php">Back to dashboard</a></p>';
        exit;
    }
}

function isAdmin(): bool   { return currentUser()['role'] === 'admin'; }
function isTech(): bool    { return currentUser()['role'] === 'tech'; }

/* ── CSRF ─────────────────────────────────────────────────────────────────── */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function verify_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        exit('Invalid or expired form token. Please go back and try again.');
    }
}

/* ── Flash messages ───────────────────────────────────────────────────────── */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Stylesheet URL with the file's mtime as a version (?v=…) so browsers can
 * never render a page with a stale copy of style.css.
 */
function style_href(): string {
    static $v = null;
    if ($v === null) {
        $v = @filemtime(dirname(__DIR__) . '/public/css/style.css') ?: 0;
    }
    return BASE_URL . '/css/style.css?v=' . (int)$v;
}

function initials(string $name): string {
    $ini = '';
    foreach (array_slice(explode(' ', trim($name)), 0, 2) as $p) {
        if ($p !== '') $ini .= strtoupper($p[0]);
    }
    return $ini ?: '?';
}
