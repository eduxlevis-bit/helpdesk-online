-- IT Helpdesk — MySQL Schema (MariaDB / MySQL)
-- Idempotent: safe to run multiple times
-- Import:  C:\xampp\mysql\bin\mysql.exe -u root < db/schema.sql
-- (Free hosting / phpMyAdmin: use db/phpmyadmin_import.sql instead)

CREATE DATABASE IF NOT EXISTS it_helpdesk
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE it_helpdesk;

-- Accounts: admins, technicians and end users
CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(150)  NOT NULL,
    email         VARCHAR(200)  NOT NULL UNIQUE,
    password_hash VARCHAR(255)  NOT NULL,
    role          ENUM('admin','tech','user') NOT NULL DEFAULT 'user',
    department    VARCHAR(100)  DEFAULT NULL,
    phone         VARCHAR(30)   DEFAULT NULL,
    remember_token  VARCHAR(64) DEFAULT NULL,          -- sha256 of the "remember me" cookie
    remember_expires DATETIME   DEFAULT NULL,
    active        TINYINT(1)    NOT NULL DEFAULT 1,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Categories: each category is handled by one department
CREATE TABLE IF NOT EXISTS categories (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(60)  NOT NULL UNIQUE,
    department VARCHAR(100) NOT NULL,
    active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Support tickets (problems registered by users)
CREATE TABLE IF NOT EXISTS tickets (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    ticket_no          VARCHAR(30)  NOT NULL UNIQUE,
    user_id            INT          NOT NULL,              -- reporter
    assigned_to        INT          DEFAULT NULL,          -- technician
    category           VARCHAR(60)  NOT NULL,
    handling_department VARCHAR(100) DEFAULT NULL,          -- department that must handle it
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

-- Replies / work notes on a ticket
CREATE TABLE IF NOT EXISTS ticket_replies (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id  INT  NOT NULL,
    user_id    INT  NOT NULL,
    body       TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)   REFERENCES users(id)   ON DELETE CASCADE
) ENGINE=InnoDB;

-- Email notifications appended to the ticket
CREATE TABLE IF NOT EXISTS ticket_emails (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    ticket_id     INT          NOT NULL,
    recipient     VARCHAR(200) NOT NULL,
    recipient_name VARCHAR(150) DEFAULT NULL,
    subject       VARCHAR(255) NOT NULL,
    event         VARCHAR(30)  NOT NULL,           -- reply | status | assign
    status        VARCHAR(20)  NOT NULL DEFAULT 'queued',  -- sent | failed | logged
    body          MEDIUMTEXT,                      -- full HTML that was sent
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    INDEX idx_ticket (ticket_id)
) ENGINE=InnoDB;

-- ── Category → department routing ──────────────────────────────────────────
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

-- ── Seed data (only when missing) ──────────────────────────────────────────

-- Admin user  (password: Admin123!)
INSERT INTO users (name, email, password_hash, role, department, phone)
SELECT * FROM (
    SELECT 'System Admin', 'admin@helpdesk.local',
           '$2y$10$wzPTvOHcO.3OkSxd3rxLXOLCbbU/eMaa8TZfQn46gYybJXIlzDSpq',
           'admin', 'IT Department' AS department, '+254700000001' AS phone
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM users);

-- Technicians — one per department (password: Tech123!)
INSERT INTO users (name, email, password_hash, role, department, phone)
SELECT * FROM (
    SELECT 'Brian Otieno', 'brian@helpdesk.local',
           '$2y$10$W.EuCG09iTdbTdu5p9gUvejVUZehrqnLesCzWygOGljae1KG1iQ0S',
           'tech', 'Infrastructure' AS department, '+254700000002' AS phone
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'brian@helpdesk.local');

INSERT INTO users (name, email, password_hash, role, department, phone)
SELECT * FROM (
    SELECT 'Grace Wanjiru', 'grace@helpdesk.local',
           '$2y$10$W.EuCG09iTdbTdu5p9gUvejVUZehrqnLesCzWygOGljae1KG1iQ0S',
           'tech', 'Applications' AS department, '+254700000003' AS phone
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'grace@helpdesk.local');

INSERT INTO users (name, email, password_hash, role, department, phone)
SELECT * FROM (
    SELECT 'Samuel Kiprop', 'samuel@helpdesk.local',
           '$2y$10$W.EuCG09iTdbTdu5p9gUvejVUZehrqnLesCzWygOGljae1KG1iQ0S',
           'tech', 'IT Support' AS department, '+254700000004' AS phone
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'samuel@helpdesk.local');

INSERT INTO users (name, email, password_hash, role, department, phone)
SELECT * FROM (
    SELECT 'Linda Achieng', 'linda@helpdesk.local',
           '$2y$10$W.EuCG09iTdbTdu5p9gUvejVUZehrqnLesCzWygOGljae1KG1iQ0S',
           'tech', 'Security' AS department, '+254700000005' AS phone
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'linda@helpdesk.local');

-- Sample end user (password: User123!)
INSERT INTO users (name, email, password_hash, role, department, phone)
SELECT * FROM (
    SELECT 'Kevin Mwangi', 'kevin@helpdesk.local',
           '$2y$10$iHlOqVpsHfB2S5t19laxbeTF3BApEEaDaO2z2Zndu826lJAB7pory',
           'user', 'Finance' AS department, '+254700000006' AS phone
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'kevin@helpdesk.local');

-- Sample tickets (only if none exist)
INSERT INTO tickets (ticket_no, user_id, assigned_to, category, handling_department, priority, status, title, description)
SELECT * FROM (
    SELECT 'HD-2026-0001' AS ticket_no, u1.id AS user_id, u2.id AS assigned_to,
           'Hardware' AS category, 'IT Support' AS handling_department,
           'High' AS priority, 'In Progress' AS status,
           'Laptop will not boot past BIOS screen' AS title,
           'Dell Latitude 5540 hangs on the Dell logo after power on. Started after a Windows update last night.' AS description
    FROM users u1, users u2
    WHERE u1.email = 'kevin@helpdesk.local' AND u2.email = 'samuel@helpdesk.local'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM tickets);

INSERT INTO tickets (ticket_no, user_id, assigned_to, category, handling_department, priority, status, title, description)
SELECT * FROM (
    SELECT 'HD-2026-0002', u1.id, NULL,
           'Network / Internet', 'Infrastructure', 'Critical', 'Open',
           'Wi-Fi keeps dropping on 2nd floor',
           'Connection drops every few minutes on the 2nd floor access point. Wired connections are fine.'
    FROM users u1 WHERE u1.email = 'kevin@helpdesk.local'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM tickets WHERE ticket_no = 'HD-2026-0002');

INSERT INTO tickets (ticket_no, user_id, assigned_to, category, handling_department, priority, status, title, description)
SELECT * FROM (
    SELECT 'HD-2026-0003', u1.id AS user_id, u2.id AS assigned_to,
           'Account & Access', 'Security', 'Medium', 'Resolved',
           'Password reset for accounting ERP',
           'User locked out of the ERP after 5 failed attempts. Account unlocked and password reset.'
    FROM users u1, users u2
    WHERE u1.email = 'kevin@helpdesk.local' AND u2.email = 'linda@helpdesk.local'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM tickets WHERE ticket_no = 'HD-2026-0003');

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