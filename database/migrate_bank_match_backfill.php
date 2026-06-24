<?php
/**
 * Миграция: перенос старых привязок выписки из legacy-колонки
 * bank_statement_lines.bank_operation_id в таблицу bank_statement_matches (M2M).
 * После этого единым источником привязок становится M2M.
 * Идемпотентна (INSERT IGNORE). Запуск: php database/migrate_bank_match_backfill.php
 */
require __DIR__ . '/../config.php';

$cli = php_sapi_name() === 'cli';

if (!tableExists($pdo, 'bank_statement_matches') || !tableExists($pdo, 'bank_statement_lines')) {
    $msg = 'Таблицы выписки отсутствуют — нечего переносить';
    echo $cli ? "$msg\n" : json_encode(['ok' => true, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$n = $pdo->exec("
    INSERT IGNORE INTO bank_statement_matches (line_id, bank_operation_id)
    SELECT bsl.id, bsl.bank_operation_id
    FROM bank_statement_lines bsl
    JOIN bank_operations bo ON bo.id = bsl.bank_operation_id
    WHERE bsl.bank_operation_id IS NOT NULL
");

$msg = "Перенесено привязок: " . (int)$n;
echo $cli ? "$msg\nГотово.\n" : json_encode(['ok' => true, 'migrated' => (int)$n], JSON_UNESCAPED_UNICODE);
