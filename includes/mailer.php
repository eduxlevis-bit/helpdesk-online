<?php
require_once __DIR__ . '/../config/config.php';

function mail_log(string $line): void {
    $dir = dirname(MAIL_LOG);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents(MAIL_LOG, '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND);
}

/** Absolute URL (emails never resolve relative paths). */
function absolute_url(string $path): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . BASE_URL . '/' . ltrim($path, '/');
}

/** Absolute link to a ticket, usable inside emails. */
function ticket_url(array $ticket): string {
    return absolute_url('/ticket-view.php?id=' . (int)$ticket['id']);
}

/** Branded HTML wrapper for outgoing mail. */
function mail_template(string $heading, string $bodyHtml): string {
    $name = e(APP_NAME);
    return '<!DOCTYPE html><html><body style="margin:0;padding:0;background:#f4f4f4;'
        . 'font-family:Arial,Helvetica,sans-serif;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:24px 0;">'
        . '<tr><td align="center"><table role="presentation" width="600" cellpadding="0" cellspacing="0" '
        . 'style="background:#ffffff;border:1px solid #dddddd;border-radius:8px;overflow:hidden;">'
        . '<tr><td style="background:#e01b24;color:#ffffff;padding:16px 22px;font-size:18px;font-weight:bold;">'
        . $name . '</td></tr>'
        . '<tr><td style="padding:22px;color:#111111;font-size:14px;line-height:1.6;">'
        . '<h2 style="margin:0 0 14px;font-size:16px;color:#111111;">' . e($heading) . '</h2>'
        . $bodyHtml
        . '</td></tr>'
        . '<tr><td style="background:#131313;color:#a3a3a3;padding:12px 22px;font-size:12px;">'
        . 'You are receiving this because you are involved in this helpdesk ticket.</td></tr>'
        . '</table></td></tr></table></body></html>';
}

function mail_meta_line(string $label, string $value): string {
    return '<p style="margin:6px 0;"><span style="color:#777777;">' . e($label) . ':</span> '
        . '<strong>' . e($value) . '</strong></p>';
}

function mail_button(string $url): string {
    return '<p style="margin:20px 0 6px;"><a href="' . e($url) . '" '
        . 'style="background:#e01b24;color:#ffffff;padding:11px 20px;border-radius:6px;'
        . 'text-decoration:none;font-weight:bold;display:inline-block;">Open ticket</a></p>';
}

/**
 * Send one email. Never throws — returns 'sent', 'logged' (MAIL_MODE=off) or 'failed'.
 */
function sendMail(string $to, string $subject, string $html, string $text = ''): string {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        mail_log('SKIPPED (invalid address) -> ' . $to . ' | ' . $subject);
        return 'failed';
    }

    if (MAIL_MODE === 'off') {
        mail_log('LOGGED -> ' . $to . ' | ' . $subject . "\n" . $html);
        return 'logged';
    }

    if (MAIL_MODE === 'smtp') {
        try {
            require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
            require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';
            require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';

            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->Port       = SMTP_PORT;
            $mail->SMTPSecure = SMTP_SECURE === 'ssl' ? 'ssl' : (SMTP_SECURE === 'tls' ? 'tls' : '');
            $mail->SMTPAuth   = SMTP_USER !== '';
            if (SMTP_USER !== '') {
                $mail->Username = SMTP_USER;
                $mail->Password = SMTP_PASS;
            }
            $mail->CharSet  = 'UTF-8';
            $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $html;
            $mail->AltBody = $text !== '' ? $text : strip_tags($html);
            $mail->send();

            mail_log('SMTP SENT -> ' . $to . ' | ' . $subject);
            return 'sent';
        } catch (Throwable $e) {
            mail_log('SMTP FAILED -> ' . $to . ' | ' . $subject . ' | ' . $e->getMessage());
            return 'failed';
        }
    }

    $headers  = 'From: ' . MAIL_FROM_NAME . ' <' . MAIL_FROM . ">\r\n";
    $headers .= 'Reply-To: ' . MAIL_FROM . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $ok = @mail($to, $subjectEnc, $html, $headers);
    mail_log(($ok ? 'SENT' : 'FAILED') . ' -> ' . $to . ' | ' . $subject);
    if (!$ok) {
        mail_log('   (PHP mail() failed — check MAIL_MODE/MAIL_FROM or switch to SMTP)');
    }
    return $ok ? 'sent' : 'failed';
}
