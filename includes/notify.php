<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mailer.php';

const NOTIFY_EVENTS = ['reply' => 'Reply', 'status' => 'Status change', 'assign' => 'Assignment'];

/** Body snippet shared by every notification. */
function notify_snippet(string $body, int $limit = 400): string {
    $text = trim($body);
    if (mb_strlen($text) > $limit) {
        $text = mb_substr($text, 0, $limit) . '…';
    }
    return '<div style="background:#f7f7f7;border-left:3px solid #e01b24;padding:10px 14px;'
        . 'margin:12px 0;border-radius:4px;color:#333333;">' . nl2br(e($text)) . '</div>';
}

/**
 * Send one notification and append it to the ticket's email history.
 */
function notify_email(array $ticket, string $event, string $email, string $name,
                      string $subject, string $heading, string $bodyHtml): void {
    $html = mail_template($heading, $bodyHtml);
    $text = strip_tags(str_replace(['<br>', '</p>', '</h2>'], "\n", $html));

    $status = 'failed';
    try {
        $status = sendMail($email, $subject, $html, $text);
    } catch (Throwable $e) {
        mail_log('NOTIFY ERROR | ' . $e->getMessage());
    }

    try {
        getDB()->prepare(
            'INSERT INTO ticket_emails (ticket_id, recipient, recipient_name, subject, event, status, body)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            (int)$ticket['id'], $email, $name, mb_substr($subject, 0, 255),
            $event, $status, $html,
        ]);
    } catch (PDOException $e) {
        mail_log('EMAIL HISTORY FAILED | ' . $e->getMessage());
    }
}

/**
 * Someone replied on a ticket -> tell the reporter and the assigned technician.
 */
function notify_ticket_replied(array $ticket, array $author, string $body): void {
    $to = [];
    if ((int)$author['id'] !== (int)$ticket['user_id'] && !empty($ticket['reporter_email'])) {
        $to[$ticket['reporter_email']] = $ticket['reporter'];
    }
    if (!empty($ticket['assigned_to'])
        && (int)$author['id'] !== (int)$ticket['assigned_to']
        && !empty($ticket['assignee_email'])) {
        $to[$ticket['assignee_email']] = $ticket['assignee'];
    }

    $subject = '[' . $ticket['ticket_no'] . '] New reply from ' . $author['name'];
    $heading = 'New reply on your ticket';
    $intro   = '<p><strong>' . e($author['name']) . '</strong> posted a reply:</p>';
    $details = mail_meta_line('Ticket', $ticket['ticket_no'] . ' — ' . $ticket['title'])
        . mail_meta_line('Status', $ticket['status']);

    foreach ($to as $email => $name) {
        notify_email($ticket, 'reply', $email, $name, $subject, $heading,
            '<p>Hi ' . e($name) . ',</p>' . $intro . $details
            . notify_snippet($body)
            . mail_button(ticket_url($ticket)));
    }
}

/**
 * A support person changed the progress/status -> tell the people involved.
 */
function notify_status_changed(array $ticket, string $oldStatus, string $newStatus, array $actor): void {
    if ($oldStatus === $newStatus) return;

    $to = [];
    if ((int)$actor['id'] !== (int)$ticket['user_id'] && !empty($ticket['reporter_email'])) {
        $to[$ticket['reporter_email']] = $ticket['reporter'];
    }
    if (!empty($ticket['assigned_to'])
        && (int)$actor['id'] !== (int)$ticket['assigned_to']
        && !empty($ticket['assignee_email'])) {
        $to[$ticket['assignee_email']] = $ticket['assignee'];
    }

    $subject = '[' . $ticket['ticket_no'] . '] Status ' . $oldStatus . ' -> ' . $newStatus;
    $heading = 'Ticket progress updated';
    $details = mail_meta_line('Ticket', $ticket['ticket_no'] . ' — ' . $ticket['title'])
        . '<p style="margin:10px 0 4px;"><span style="color:#777777;">Progress:</span> '
        . '<span style="color:#8a8a8a;text-decoration:line-through;">' . e($oldStatus) . '</span> '
        . '<strong style="color:#16a34a;">' . e($newStatus) . '</strong></p>'
        . mail_meta_line('Updated by', $actor['name'] . ' (' . role_label($actor['role']) . ')');

    foreach ($to as $email => $name) {
        notify_email($ticket, 'status', $email, $name, $subject, $heading,
            '<p>Hi ' . e($name) . ',</p><p>Your ticket was updated:</p>' . $details
            . mail_button(ticket_url($ticket)));
    }
}

/**
 * A ticket was assigned to a technician -> tell the technician and the reporter.
 */
function notify_ticket_assigned(array $ticket, array $tech, array $actor): void {
    $subject = '[' . $ticket['ticket_no'] . '] Assigned to ' . $tech['name'];
    $heading = 'Ticket assigned';
    $details = mail_meta_line('Ticket', $ticket['ticket_no'] . ' — ' . $ticket['title'])
        . mail_meta_line('Category', $ticket['category'] . ' → ' . ($ticket['handling_department'] ?: 'no team'))
        . mail_meta_line('Priority', $ticket['priority'])
        . mail_meta_line('Assigned by', $actor['name']);

    $phone  = trim($tech['phone'] ?? '');
    $contact = '<div style="background:#f7f7f7;border-left:3px solid #e01b24;padding:12px 16px;'
        . 'margin:16px 0;border-radius:4px;">'
        . '<p style="margin:0 0 8px;color:#0a0a0a;"><strong>Your technician</strong></p>'
        . mail_meta_line('Name', $tech['name'])
        . mail_meta_line('Email', '<a href="mailto:' . e($tech['email']) . '" style="color:#e01b24;">'
            . e($tech['email']) . '</a>');
    if ($phone !== '') {
        $contact .= mail_meta_line('Phone', '<a href="tel:' . e(preg_replace('/[^0-9+]/', '', $phone))
            . '" style="color:#e01b24;">' . e($phone) . '</a>');
    }
    $contact .= '<p style="margin:8px 0 0;color:#666666;font-size:13px;">'
        . 'Call or email them directly to give directions to the exact location or for any further communication.'
        . '</p></div>';

    if (!empty($tech['email']) && (int)$actor['id'] !== (int)$tech['id']) {
        notify_email($ticket, 'assign', $tech['email'], $tech['name'], $subject,
            'A ticket needs your attention',
            '<p>Hi ' . e($tech['name']) . ',</p>'
            . '<p>You have been assigned a ticket in your department:</p>' . $details
            . notify_snippet($ticket['description'])
            . mail_button(ticket_url($ticket)));
    }

    if (!empty($ticket['reporter_email']) && (int)$actor['id'] !== (int)$ticket['user_id']) {
        notify_email($ticket, 'assign', $ticket['reporter_email'], $ticket['reporter'],
            '[' . $ticket['ticket_no'] . '] Your ticket was assigned to ' . $tech['name'],
            'Help is on the way',
            '<p>Hi ' . e($ticket['reporter']) . ',</p>'
            . '<p>Your ticket was assigned to <strong>' . e($tech['name']) . '</strong>:</p>' . $details
            . $contact
            . mail_button(ticket_url($ticket)));
    }
}

/**
 * Someone edited their own name / email / phone -> tell every administrator.
 * (No ticket involved, so it is not written to ticket_emails.)
 */
function notify_profile_updated(array $user, array $changes): void {
    $rows = '';
    foreach ($changes as $label => $pair) {
        $rows .= '<p style="margin:6px 0;"><span style="color:#777777;">' . e($label) . ':</span> '
            . '<span style="color:#8a8a8a;text-decoration:line-through;">' . e($pair[0]) . '</span> '
            . '<strong style="color:#16a34a;">' . e($pair[1]) . '</strong></p>';
    }

    try {
        $admins = getDB()->query(
            "SELECT id, name, email FROM users WHERE role = 'admin' AND active = 1"
        )->fetchAll();
    } catch (Throwable $e) {
        mail_log('PROFILE NOTIFY FAILED | ' . $e->getMessage());
        return;
    }

    $subject = 'Profile updated: ' . $user['name'];
    foreach ($admins as $admin) {
        if ((int)$admin['id'] === (int)$user['id']) continue;   // never mail yourself

        $html = mail_template('A user updated their profile',
            '<p>Hi ' . e($admin['name']) . ',</p>'
            . '<p><strong>' . e($user['name']) . '</strong> (' . e(role_label($user['role'])) . ') '
            . 'changed their account details:</p>' . $rows
            . '<p style="margin-top:12px;color:#666666;font-size:13px;">'
            . 'The change is already live on every ticket and dashboard.</p>');
        $text   = strip_tags(str_replace(['<br>', '</p>', '</h2>'], "\n", $html));
        $status = 'failed';
        try {
            $status = sendMail($admin['email'], $subject, $html, $text);
        } catch (Throwable $e) {
            mail_log('PROFILE NOTIFY ERROR | ' . $e->getMessage());
        }
        mail_log(strtoupper($status) . ' -> ' . $admin['email'] . ' | ' . $subject);
    }
}
