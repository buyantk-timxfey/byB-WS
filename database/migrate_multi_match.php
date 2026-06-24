<?php
/**
 * Миграция: поддержка нескольких операций на одну строку выписки.
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
        $msg = in_array($code, [1060,1061,1146,1050,1005], true)
            ? "SKIP: $label"
            : "ERR:  $label — " . $e->getMessage();
    }
    $log[] = $msg;
    if ($cli) echo $msg . "\n";
}

// 1. Таблица связей «строка ↔ операция» (many-to-many)
run($pdo, "
CREATE TABLE IF NOT EXISTS bank_statement_matches (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    line_id             INT NOT NULL,
    bank_operation_id   INT NOT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_match (line_id, bank_operation_id),
    KEY idx_bsm_line (line_id),
    KEY idx_bsm_op   (bank_operation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "CREATE bank_statement_matches");

// 2. Мигрируем уже сохранённые одиночные совпадения
run($pdo, "
INSERT IGNORE INTO bank_statement_matches (line_id, bank_operation_id)
SELECT id, bank_operation_id
FROM bank_statement_lines
WHERE bank_operation_id IS NOT NULL AND status = 'matched'
", "migrate existing matches");

// 3. Добавляем статус 'partial' в ENUM
run($pdo, "
ALTER TABLE bank_statement_lines
MODIFY COLUMN status ENUM('unmatched','partial','matched','ignored') NOT NULL DEFAULT 'unmatched'
", "bank_statement_lines add partial status");

if (!$cli) { header('Content-Type: application/json'); echo json_encode(['ok'=>true,'log'=>$log]); }
else echo "\nГотово.\n";
