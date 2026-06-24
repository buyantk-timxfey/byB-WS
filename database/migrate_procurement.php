<?php
/**
 * Миграция: таблицы планов закупок (procurement_plans, plan_items, plan_variants).
 * Раньше создавались на каждый запрос внутри api/procurement.php — вынесено сюда.
 * Запуск: php database/migrate_procurement.php
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
CREATE TABLE IF NOT EXISTS procurement_plans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  status VARCHAR(50) DEFAULT 'В работе',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "CREATE procurement_plans");

run($pdo, "
CREATE TABLE IF NOT EXISTS plan_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  plan_id INT NOT NULL,
  name VARCHAR(255) NOT NULL,
  quantity INT DEFAULT 1,
  winner_variant_id INT DEFAULT NULL,
  KEY idx_plan_items_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "CREATE plan_items");

run($pdo, "
CREATE TABLE IF NOT EXISTS plan_variants (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  supplier_name VARCHAR(255),
  price DECIMAL(12,2) DEFAULT 0,
  delivery_days INT DEFAULT NULL,
  min_qty INT DEFAULT NULL,
  payment_terms VARCHAR(255),
  link VARCHAR(500),
  notes TEXT,
  carrier_cost DECIMAL(12,2) DEFAULT NULL,
  KEY idx_plan_variants_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
", "CREATE plan_variants");

// Догоняющие миграции для ранее созданных таблиц
run($pdo, "ALTER TABLE plan_variants ADD COLUMN carrier_cost DECIMAL(12,2) DEFAULT NULL", "plan_variants.carrier_cost");
run($pdo, "ALTER TABLE plan_items ADD COLUMN name VARCHAR(255) NOT NULL DEFAULT '' AFTER plan_id", "plan_items.name");
run($pdo, "ALTER TABLE plan_items ADD COLUMN quantity INT DEFAULT 1", "plan_items.quantity");

if (!$cli) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'log' => $log]);
} else {
    echo "\nГотово.\n";
}
