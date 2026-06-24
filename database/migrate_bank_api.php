<?php
/**
 * Миграция: поля интеграции с банковскими/маркетплейс API у bank_accounts.
 *  provider             — 'tochka' | 'ozon' | NULL (ручной счёт)
 *  api_token            — JWT (Точка) или Api-Key (Ozon)
 *  api_client_id        — Client-Id (Ozon)
 *  api_customer_code    — customerCode (Точка)
 *  api_account_id       — accountId счёта в Точке
 *  api_statement_id     — id асинхронной выписки Точки (двухшаговый запрос)
 *  api_statement_status — статус последней асинхронной выписки
 * Идемпотентна. Запуск: php database/migrate_bank_api.php
 */
require __DIR__ . '/../config.php';

$cli = php_sapi_name() === 'cli';
$log = [];

$cols = [
    'provider'             => "VARCHAR(20) NULL",
    'api_token'            => "TEXT NULL",
    'api_client_id'        => "VARCHAR(100) NULL",
    'api_customer_code'    => "VARCHAR(80) NULL",
    'api_account_id'       => "VARCHAR(80) NULL",
    'api_server_url'       => "VARCHAR(255) NULL",   // кастомный URL сервера (Точка DirectBank)
    'api_statement_id'     => "VARCHAR(80) NULL",
    'api_statement_status' => "VARCHAR(20) NULL",
];

foreach ($cols as $name => $def) {
    if (columnExists($pdo, 'bank_accounts', $name)) {
        $msg = "SKIP: bank_accounts.$name (есть)";
    } else {
        try {
            $pdo->exec("ALTER TABLE bank_accounts ADD COLUMN $name $def");
            $msg = "OK:   bank_accounts.$name";
        } catch (PDOException $e) {
            $msg = "ERR:  bank_accounts.$name — " . $e->getMessage();
        }
    }
    $log[] = $msg;
    if ($cli) echo $msg . "\n";
}

if (!$cli) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'log' => $log], JSON_UNESCAPED_UNICODE);
} else {
    echo "\nГотово.\n";
}
