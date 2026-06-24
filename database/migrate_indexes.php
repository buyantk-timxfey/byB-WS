<?php
/**
 * Одноразовый раннер: добавляет индексы на FK/часто фильтруемые колонки.
 * Запуск (CLI):  php database/migrate_indexes.php
 * Или из браузера один раз, затем удалить с сервера.
 *
 * Идемпотентен: повторный «Duplicate key name» молча игнорируется.
 */
require __DIR__ . '/../config.php';

$indexes = [
    "ALTER TABLE shipment_items ADD INDEX idx_si_shipment (shipment_id)",
    "ALTER TABLE sale_items     ADD INDEX idx_sli_sale (sale_id)",
    "ALTER TABLE sale_items     ADD INDEX idx_sli_warehouse (warehouse_id)",
    "ALTER TABLE notes          ADD INDEX idx_notes_entity (entity_type, entity_id)",
    "ALTER TABLE reminders      ADD INDEX idx_rem_entity (entity_type, entity_id)",
    "ALTER TABLE bank_operations ADD INDEX idx_bo_shipment (shipment_id)",
    "ALTER TABLE bank_operations ADD INDEX idx_bo_sale (sale_id)",
    "ALTER TABLE warehouse      ADD INDEX idx_wh_shipment (shipment_id)",
    "ALTER TABLE warehouse      ADD INDEX idx_wh_shipment_item (shipment_item_id)",
    "ALTER TABLE warehouse      ADD INDEX idx_wh_status (status)",
    "ALTER TABLE shipments      ADD INDEX idx_sh_status (status)",
    "ALTER TABLE shipments      ADD INDEX idx_sh_order_date (order_date)",
    "ALTER TABLE shipments      ADD INDEX idx_sh_eta (eta)",
    "ALTER TABLE plan_variants  ADD INDEX idx_pv_item (item_id)",
    "ALTER TABLE plan_items     ADD INDEX idx_pi_plan (plan_id)",
];

$cli = php_sapi_name() === 'cli';
$ok = 0; $skip = 0; $errors = [];

foreach ($indexes as $sql) {
    try {
        $pdo->exec($sql);
        $ok++;
        if ($cli) echo "OK:   $sql\n";
    } catch (PDOException $e) {
        // 1061 = Duplicate key name, 1146 = таблицы нет, 1054/1091 — колонки/индекса нет
        $code = $e->errorInfo[1] ?? 0;
        if (in_array($code, [1061, 1146, 1054, 1091], true)) {
            $skip++;
            if ($cli) echo "SKIP: $sql ({$code})\n";
        } else {
            $errors[] = $e->getMessage();
            if ($cli) echo "ERR:  $sql -> " . $e->getMessage() . "\n";
        }
    }
}

$summary = ['added' => $ok, 'skipped' => $skip, 'errors' => $errors];
if (!$cli) { header('Content-Type: application/json'); echo json_encode($summary); }
else echo "\nИтог: добавлено $ok, пропущено $skip, ошибок " . count($errors) . "\n";
