-- ============================================================
-- Миграция 003: создание таблиц банковских выписок
-- Совместима с MySQL 5.7+. Идемпотентна (CREATE TABLE IF NOT EXISTS).
-- ============================================================

CREATE TABLE IF NOT EXISTS bank_statement_lines (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    account_id       INT NULL,
    direction        ENUM('in','out') NOT NULL,
    amount           DECIMAL(12,2) NOT NULL,
    operation_date   DATE NOT NULL,
    value_date       DATE NULL,
    counterparty     VARCHAR(255) NULL,
    counterparty_inn VARCHAR(12)  NULL,
    description      TEXT,
    doc_number       VARCHAR(50)  NULL,
    raw_section      JSON NULL,
    import_batch     VARCHAR(50)  NULL,
    status           ENUM('unmatched','partial','matched','ignored') NOT NULL DEFAULT 'unmatched',
    bank_operation_id INT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_statement_matches (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    line_id             INT NOT NULL,
    bank_operation_id   INT NOT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_match (line_id, bank_operation_id),
    FOREIGN KEY (line_id)           REFERENCES bank_statement_lines(id) ON DELETE CASCADE,
    FOREIGN KEY (bank_operation_id) REFERENCES bank_operations(id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_statement_batches (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    account_id       INT NULL,
    import_batch     VARCHAR(50) NOT NULL,
    start_date       DATE NULL,
    end_date         DATE NULL,
    opening_balance  DECIMAL(12,2) NULL,
    closing_balance  DECIMAL(12,2) NULL,
    total_in         DECIMAL(12,2) NULL,
    total_out        DECIMAL(12,2) NULL,
    account_number   VARCHAR(30)  NULL,
    imported_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES bank_accounts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
