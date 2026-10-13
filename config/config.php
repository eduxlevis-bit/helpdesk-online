<?php
define('APP_NAME', 'IT Helpdesk');

/**
 * Auto-detect BASE_URL — works for XAMPP (localhost/folder) and root domains.
 */
$script_name = $_SERVER['SCRIPT_NAME'] ?? '/';
$public_pos  = strpos($script_name, '/public/');

if ($public_pos !== false) {
    $base_path = substr($script_name, 0, $public_pos + 7); // includes /public
} else {
    $base_path = rtrim(dirname($script_name), '/\\');
    if (strpos($base_path, '/public') === false
        && file_exists(($_SERVER['DOCUMENT_ROOT'] ?? '') . $base_path . '/public')) {
        $base_path .= '/public';
    }
}

define('BASE_PATH', $base_path);
define('BASE_URL',  BASE_PATH);

/* ── Secrets: config/.env ─────────────────────────────────────────────────────
   Every deployment-specific secret lives in config/.env (DB password, Gmail
   app password), which is NEVER uploaded to the web — config/.htaccess
   blocks direct access, and the deploy zip ships config/.env.example only.

   Copy .env.example -> .env, fill in the values, done. If .env is absent the
   defaults below are used (XAMPP local). */
if (!function_exists('env_load')) {
    function env_load(string $file): array
    {
        $out = [];
        if (!is_file($file)) return $out;
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $p = strpos($line, '=');
            if ($p === false) continue;
            $key = trim(substr($line, 0, $p));
            $val = trim(substr($line, $p + 1));
            // strip matching quotes
            if ($val !== '' && ($val[0] === '"' || $val[0] === "'")
                && substr($val, -1, 1) === $val[0]) {
                $val = substr($val, 1, -1);
            }
            $out[$key] = $val;
        }
        return $out;
    }
}
$GLOBALS['__env'] = env_load(__DIR__ . '/.env');
if (!function_exists('env')) {
    function env(string $key, $default = '')
    {
        $v = $GLOBALS['__env'][$key] ?? '';
        return ($v !== '') ? $v : $default;
    }
}

/* ── Database ──────────────────────────────────────────────────────────────── */
define('MYSQL_HOST', env('MYSQL_HOST', '127.0.0.1'));
define('MYSQL_PORT', env('MYSQL_PORT', '3306'));
define('MYSQL_DB',   env('MYSQL_DB',   'it_helpdesk'));
define('MYSQL_USER', env('MYSQL_USER', 'root'));
define('MYSQL_PASS', env('MYSQL_PASS', ''));

/* ── Session ──────────────────────────────────────────────────────────────── */
define('SESSION_NAME',   'helpdesk_session');
define('IDLE_TIMEOUT',   1800); // 30 minutes

/* ── Mail ────────────────────────────────────────────────────────────────────
   MAIL_MODE: 'mail' = PHP mail() — works on most shared hosts
              'smtp' = SMTP account (Gmail, cPanel, Mailgun, …)
              'off'  = send nothing, write every message to storage/logs/mail.log */
define('MAIL_MODE',      env('MAIL_MODE', 'smtp'));
define('MAIL_FROM',      env('MAIL_FROM', ''));
define('MAIL_FROM_NAME', APP_NAME);
define('MAIL_LOG',       dirname(__DIR__) . '/storage/logs/mail.log');

define('SMTP_HOST',      env('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT',      (int)env('SMTP_PORT', '587'));
define('SMTP_SECURE',    env('SMTP_SECURE', 'tls'));   // tls | ssl | ''
define('SMTP_USER',      env('SMTP_USER', ''));
define('SMTP_PASS',      env('SMTP_PASS', ''));

/* ── Ticket settings ──────────────────────────────────────────────────────── */
define('TICKET_PREFIX', 'HD');
define('PRIORITIES', ['Low', 'Medium', 'High', 'Critical']);
define('STATUSES',   ['Open', 'Assigned', 'In Progress', 'Resolved', 'Closed']);
define('ROLES',      ['admin' => 'Administrator', 'tech' => 'Technician', 'user' => 'User']);
