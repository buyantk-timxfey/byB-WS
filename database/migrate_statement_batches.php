<?php
/**
 * Миграция: таблица метаданных батчей выписки (контрольные суммы).
 * Запуск: php database/migrate_statement_batches.php
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

run($pdo, "
CREATE TABLE IF NOT EXISTS bank_statement_batches (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    account_id       INT NOT NULL,
    import_batch     VARCHAR(64) NOT NULL,
    start_date       DATE,
    end_date         DATE,
    opening_balance  DECIMAL(14,2),
    closing_balance  DECIMAL(14,2),
    total_in         DECIMAL(14,2),
    total_out        DECIMAL(14,2),
    account_number   VARCHAR(50),
    imported_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_batch (import_batch),
    KEY idx_bsb_account (account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "CREATE bank_statement_batches");

// Добавить 'partial' статус в bank_statement_lines если ещё не добавлен migrate_multi_match
run($pdo,
    "ALTER TABLE bank_statement_lines MODIFY COLUMN status ENUM('unmatched','partial','matched','ignored') NOT NULL DEFAULT 'unmatched'",
    "bank_statement_lines.status add partial"
);

if (!$cli) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'log' => $log]);
} else {
    echo "\nГотово.\n";
}
