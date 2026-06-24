<?php
/**
 * Миграция: переводы между счетами.
 *  - добавляет в enum bank_operations.type значения 'Выплата ЗП' и 'Перевод'
 *  - добавляет колонку transfer_id (связка пары операций перевода)
 * Идемпотентна — повторный запуск безопасен.
 * Запуск: php database/migrate_transfers.php
 * Или один раз из браузера, затем удалить с сервера.
 */
require __DIR__ . '/../config.php';

$cli = php_sapi_name() === 'cli';
$log = [];

function step(string $label, callable $fn): void {
    global $log, $cli;
    try {
        $fn();
        $msg = "OK:   $label";
    } catch (PDOException $e) {
        $code = $e->errorInfo[1] ?? 0;
        $msg = in_array($code, [1060, 1061, 1050], true)
            ? "SKIP: $label (уже применено)"
            : "ERR:  $label — " . $e->getMessage();
    }
    $log[] = $msg;
    if ($cli) echo $msg . "\n";
}

// enum MODIFY идемпотентен — повторное применение к той же схеме безвредно
step("bank_operations.type +'Выплата ЗП','Перевод'", function () use ($pdo) {
    $pdo->exec("
        ALTER TABLE bank_operations
        MODIFY COLUMN type ENUM('Продажа','Закупка','Прочий приход','Расход','Выплата ЗП','Перевод') NOT NULL
    ");
});

// ADD COLUMN не идемпотентен — проверяем наличие колонки заранее
if (columnExists($pdo, 'bank_operations', 'transfer_id')) {
    $msg = "SKIP: bank_operations.transfer_id (уже применено)";
    $log[] = $msg;
    if ($cli) echo $msg . "\n";
} else {
    step("bank_operations.transfer_id", function () use ($pdo) {
        $pdo->exec("ALTER TABLE bank_operations ADD COLUMN transfer_id INT NULL");
    });
}

if (!$cli) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'log' => $log], JSON_UNESCAPED_UNICODE);
} else {
    echo "\nГотово.\n";
}
