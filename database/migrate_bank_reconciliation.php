<?php
/**
 * Миграция: система сопоставления банковских платежей.
 * Запуск: php database/migrate_bank_reconciliation.php
 * Или один раз из браузера, затем удалить с сервера.
 */
require __DIR__ . '/../config.php';

$cli = php_sapi_name() === 'cli';
$log = [];

function run(PDO $pdo, string $sql, string $label): void {
    global $log, $cli;
    try {
        $pdo->exec($sql);
        $msg = "OK:   $label";
    } catch (PDOException $e) {
        $code = $e->errorInfo[1] ?? 0;
        $msg = in_array($code, [1060, 1061, 1146, 1050], true)
            ? "SKIP: $label (уже существует)"
            : "ERR:  $label — " . $e->getMessage();
    }
    $log[] = $msg;
    if ($cli) echo $msg . "\n";
}

// 1. Добавить status в bank_operations
run($pdo,
    "ALTER TABLE bank_operations ADD COLUMN status ENUM('confirmed','pending') NOT NULL DEFAULT 'confirmed'",
    "bank_operations.status"
);
run($pdo,
    "ALTER TABLE bank_operations ADD INDEX idx_bo_status (status)",
    "bank_operations index status"
);

// 2. Таблица строк выписки
run($pdo, "
CREATE TABLE IF NOT EXISTS bank_statement_lines (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    account_id          INT,
    direction           ENUM('in','out') NOT NULL,
    amount              DECIMAL(14,2) NOT NULL,
    operation_date      DATE NOT NULL,
    value_date          DATE,
    counterparty        VARCHAR(255),
    counterparty_inn    VARCHAR(20),
    description         TEXT,
    doc_number          VARCHAR(50),
    raw_section         TEXT,
    bank_operation_id   INT DEFAULT NULL,
    status              ENUM('unmatched','matched','ignored') NOT NULL DEFAULT 'unmatched',
    import_batch        VARCHAR(64),
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_bsl_status (status),
    KEY idx_bsl_account (account_id),
    KEY idx_bsl_op (bank_operation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "CREATE bank_statement_lines");

if (!$cli) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'log' => $log]);
} else {
    echo "\nГотово.\n";
}
