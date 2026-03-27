-- =====================================================
-- Mailbox - PST Email Manager
-- Schema Database MySQL 8.0+
-- =====================================================

CREATE DATABASE IF NOT EXISTS mailbox
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE mailbox;

-- -----------------------------------------------------
-- Tabella: users
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    username     VARCHAR(100)  NOT NULL UNIQUE,
    email        VARCHAR(255)  NOT NULL UNIQUE,
    password     VARCHAR(255)  NOT NULL COMMENT 'Hash bcrypt',
    is_admin     TINYINT(1)    DEFAULT 0,
    created_at   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    last_login   TIMESTAMP     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Tabella: pst_imports
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS pst_imports (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    original_filename VARCHAR(500)  NOT NULL,
    stored_filename   VARCHAR(500)  COMMENT 'Nome file salvato in storage/pst/',
    file_size_mb      DECIMAL(10,2),
    total_emails      INT           DEFAULT 0,
    imported_emails   INT           DEFAULT 0,
    skipped_emails    INT           DEFAULT 0 COMMENT 'Duplicati ignorati',
    error_emails      INT           DEFAULT 0,
    status            ENUM('pending','extracting','importing','completed','error')
                      DEFAULT 'pending',
    started_at        TIMESTAMP     NULL,
    completed_at      TIMESTAMP     NULL,
    error_message     TEXT,
    imported_by       INT           NULL,
    created_at        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (imported_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Tabella: folders
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS folders (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    pst_import_id   INT           NOT NULL,
    folder_name     VARCHAR(255)  NOT NULL,
    full_path       VARCHAR(1000) COMMENT 'Es: Inbox/Lavoro/2024',
    email_count     INT           DEFAULT 0,
    FOREIGN KEY (pst_import_id) REFERENCES pst_imports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Tabella: emails (principale)
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS emails (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    message_id      VARCHAR(500)   COMMENT 'Message-ID header RFC 2822',
    from_address    VARCHAR(500)   NOT NULL,
    from_name       VARCHAR(255),
    to_address      TEXT           NOT NULL,
    cc_address      TEXT,
    bcc_address     TEXT,
    reply_to        VARCHAR(500),
    subject         VARCHAR(1000),
    body_text       LONGTEXT       COMMENT 'Corpo in testo semplice',
    body_html       LONGTEXT       COMMENT 'Corpo in HTML',
    email_date      DATETIME       NOT NULL,
    has_attachments TINYINT(1)     DEFAULT 0,
    attachment_count INT           DEFAULT 0,
    folder_id       INT            NULL,
    folder_name     VARCHAR(255)   COMMENT 'Nome cartella PST',
    pst_import_id   INT            NULL,
    pst_filename    VARCHAR(500),
    size_bytes      INT,
    is_read         TINYINT(1)     DEFAULT 0,
    is_flagged      TINYINT(1)     DEFAULT 0,
    tags            VARCHAR(500)   COMMENT 'Etichette separate da virgola',
    imported_at     TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,

    -- Indici per performance
    FULLTEXT INDEX ft_search       (from_address, to_address, subject, body_text),
    INDEX idx_date                 (email_date),
    INDEX idx_from                 (from_address(150)),
    INDEX idx_to                   (to_address(150)),
    INDEX idx_subject              (subject(150)),
    INDEX idx_folder               (folder_id),
    INDEX idx_import               (pst_import_id),
    INDEX idx_msg_id               (message_id(100)),
    INDEX idx_has_attachments      (has_attachments),
    UNIQUE KEY uq_message_id       (message_id(200)),

    FOREIGN KEY (folder_id)      REFERENCES folders(id)     ON DELETE SET NULL,
    FOREIGN KEY (pst_import_id)  REFERENCES pst_imports(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Tabella: attachments
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS attachments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    email_id        INT            NOT NULL,
    filename        VARCHAR(500)   NOT NULL,
    content_type    VARCHAR(255),
    file_path       VARCHAR(1000)  COMMENT 'Path relativo a STORAGE_PATH',
    file_size       INT            COMMENT 'Dimensione in bytes',
    checksum_md5    CHAR(32)       COMMENT 'Hash MD5 per deduplicazione',
    created_at      TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email_id),
    FOREIGN KEY (email_id) REFERENCES emails(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------
-- Utente admin di default
-- Password: admin (da cambiare!)
-- -----------------------------------------------------
INSERT IGNORE INTO users (username, email, password, is_admin)
VALUES ('admin', 'admin@mailbox.local', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1);
