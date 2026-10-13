-- IT Helpdesk — portable import for phpMyAdmin (free hosting / cPanel)
--
-- How to import:
--   1. phpMyAdmin -> select the database your host created for you
--   2. Import tab -> choose this file -> Go
--   3. Open https://your-site/helpdesk/public/install.php
--      -> "Create tables" (safe to skip, tables already exist)
--      -> "Create account" to make your admin / technician logins
--
-- Notes:
--   * Does NOT create or switch databases (shared hosts usually forbid it).
--   * Safe to re-run: every table uses IF NOT EXISTS.
--   * Categories decide WHICH DEPARTMENT sees a ticket — manage them later
--     at Categories in the admin menu.
--   * No user accounts are seeded here — create them in install.php (or in
--     the application at Users & Technicians) so passwords are properly hashed.
--   * Columns are identical to db/schema.sql (local/XAMPP version).

-- ── User / access accounts table ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(150)  NOT NULL,
    email         VARCHAR(200)  NOT NULL UNIQUE,
    password_hash VARCHAR(255)  NOT NULL,
    role          ENUM('admin','tech','user') NOT NULL DEFAULT 'user',
    department    VARCHAR(100)  DEFAULT NULL,
    phone         VARCHAR(30)   DEFAULT NULL,
    remember_token  VARCHAR(64) DEFAULT NULL,
    remember_expires DATETIME   DEFAULT NULL,
    active        TINYINT(1)    NOT NULL DEFAULT 1,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── Categories: each category is handled by one department ─────────────────
CREATE TABLE IF NOT EXISTS categories (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(60)  NOT NULL UNIQUE,
    department VARCHAR(100) NOT NULL,
    active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── Support tickets (problems registered by users) ─────────────────────────
CREATE TABLE IF NOT EXISTS tickets (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    ticket_no          VARCHAR(30)  NOT NULL UNIQUE,
    user_id            INT          NOT NULL,
    assigned_to        INT          DEFAULT NULL,
    category           VARCHAR(60)  NOT NULL,
    handling_department VARCHAR(100) DEFAULT NULL,
    priority           ENUM('Low','Medium','High','Critical') NOT NULL DEFAULT 'Medium',
    status             ENUM('Open','Assigned','In Progress','Resolved','Closed') NOT NULL DEFAULT 'Open',
    title              VARCHAR(200) NOT NULL,
    description        TEXT         NOT NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id)     REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_assignee (assigned_to),
    INDEX idx_team (handling_department)
) ENGINE=InnoDB;

-- ── Replies / work notes on a ticket ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS ticket_replies (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id  INT  NOT NULL,
    user_id    INT  NOT NULL,
    body       TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- ── Email notifications appended to the ticket ─────────────────────────────
CREATE TABLE IF NOT EXISTS ticket_emails (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id      INT          NOT NULL,
    recipient      VARCHAR(200) NOT NULL,
    recipient_name VARCHAR(150) DEFAULT NULL,
    subject        VARCHAR(255) NOT NULL,
    event          VARCHAR(30)  NOT NULL,
    status         VARCHAR(20)  NOT NULL DEFAULT 'queued',
    body           MEDIUMTEXT,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    INDEX idx_ticket (ticket_id)
) ENGINE=InnoDB;

-- ── Category -> department routing (inserted only when missing) ────────────
INSERT INTO categories (name, department) SELECT 'Hardware', 'IT Support' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Hardware');
INSERT INTO categories (name, department) SELECT 'Printer / Peripheral', 'IT Support' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Printer / Peripheral');
INSERT INTO categories (name, department) SELECT 'Other', 'IT Support' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Other');
INSERT INTO categories (name, department) SELECT 'Network / Internet', 'Infrastructure' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Network / Internet');
INSERT INTO categories (name, department) SELECT 'Software / OS', 'Applications' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Software / OS');
INSERT INTO categories (name, department) SELECT 'Email', 'Applications' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Email');
INSERT INTO categories (name, department) SELECT 'Account & Access', 'Security' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Account & Access');
INSERT INTO categories (name, department) SELECT 'Security', 'Security' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE name = 'Security');

-- Password reset links (forgotten password)
CREATE TABLE IF NOT EXISTS password_resets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT         NOT NULL,
    token_hash VARCHAR(64) NOT NULL,              -- sha256 of the link token
    expires_at DATETIME    NOT NULL,
    created_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token_hash),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;