<?php
require_once '../config.php';
session_start();
if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
    http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit;
}
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // Следующий номер для заголовка формы — без загрузки всего списка
            if (isset($_GET['next_number'])) {
                $next = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM shipments")->fetchColumn();
                echo json_encode(['next' => $next]);
                break;
            }
            // Позиции из поставок «В пути», ещё не попавшие на склад — для продажи в пути
            if (isset($_GET['in_transit_items'])) {
                $rows = $pdo->query("
                    SELECT si.id AS shipment_item_id, si.name, si.quantity, si.purchase_price,
                           s.id AS shipment_id, c.name AS counterparty
                    FROM shipment_items si
                    JOIN shipments s ON s.id = si.shipment_id
                    LEFT JOIN counterparties c ON c.id = s.counterparty_id
                    WHERE s.status = 'В пути'
                      AND NOT EXISTS (SELECT 1 FROM warehouse w WHERE w.shipment_item_id = si.id)
                      AND si.quantity > 0
                    ORDER BY s.order_date DESC, si.name
                ")->fetchAll();
                echo json_encode($rows);
                break;
            }
            if (isset($_GET['id'])) {
                $stmt = $pdo->prepare("
                    SELECT s.*, c.name as counterparty, c.company_type, cr.name as carrier,
                        (SELECT COUNT(*) FROM shipments s2 WHERE s2.id <= s.id) as display_num
                    FROM shipments s
                    LEFT JOIN counterparties c ON c.id = s.counterparty_id
                    LEFT JOIN carriers cr ON cr.id = s.carrier_id
                    WHERE s.id = ?
                ");
                $stmt->execute([$_GET['id']]);
                $shipment = $stmt->fetch();

                $stmt2 = $pdo->prepare("
                    SELECT si.*, w.id as warehouse_id
                    FROM shipment_items si
                    LEFT JOIN warehouse w ON w.shipment_item_id = si.id
                    WHERE si.shipment_id = ?
                ");
                $stmt2->execute([$_GET['id']]);
                $items = $stmt2->fetchAll();
                $shipment['items'] = $items;
                $shipment['total'] = array_reduce($items, fn($c, $i) => $c + $i['quantity'] * $i['purchase_price'], 0) + $shipment['carrier_cost'];
                echo json_encode($shipment);
                break;
            }

            $rows = $pdo->query("
                SELECT s.*, c.name as counterparty, c.company_type, cr.name as carrier,
                    COALESCE(SUM(si.quantity * si.purchase_price), 0) + s.carrier_cost as total,
                    GROUP_CONCAT(si.name SEPARATOR ', ') as items_list,
                    ROW_NUMBER() OVER (ORDER BY s.id) as display_num
                FROM shipments s
                LEFT JOIN counterparties c ON c.id = s.counterparty_id
                LEFT JOIN carriers cr ON cr.id = s.carrier_id
                LEFT JOIN shipment_items si ON si.shipment_id = s.id
                GROUP BY s.id
                ORDER BY s.order_date DESC
            ")->fetchAll();
            echo json_encode($rows);
            break;

        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);

            $shipments = isset($data['shipments']) ? $data['shipments'] : [$data];
            $createdIds = [];

            // Все поставки пакета создаются атомарно
            $hasAccountId   = columnExists($pdo, 'shipments', 'account_id');
            $hasBankOps     = tableExists($pdo, 'bank_operations');
            $pdo->beginTransaction();
            try {
            foreach ($shipments as $sh) {
                if (empty($sh['order_date'])) continue;

                if ($hasAccountId) {
                    $stmt = $pdo->prepare("INSERT INTO shipments (name, order_date, eta, counterparty_id, carrier_id, tracking, status, carrier_cost, account_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        !empty($sh['name']) ? $sh['name'] : null,
                        $sh['order_date'],
                        !empty($sh['eta']) ? $sh['eta'] : null,
                        !empty($sh['counterparty_id']) ? $sh['counterparty_id'] : null,
                        !empty($sh['carrier_id']) ? $sh['carrier_id'] : null,
                        $sh['tracking'] ?? '',
                        $sh['status'] ?? 'Ожидает отправки',
                        $sh['carrier_cost'] ?? 0,
                        !empty($sh['account_id']) ? $sh['account_id'] : null
                    ]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO shipments (name, order_date, eta, counterparty_id, carrier_id, tracking, status, carrier_cost) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        !empty($sh['name']) ? $sh['name'] : null,
                        $sh['order_date'],
                        !empty($sh['eta']) ? $sh['eta'] : null,
                        !empty($sh['counterparty_id']) ? $sh['counterparty_id'] : null,
                        !empty($sh['carrier_id']) ? $sh['carrier_id'] : null,
                        $sh['tracking'] ?? '',
                        $sh['status'] ?? 'Ожидает отправки',
                        $sh['carrier_cost'] ?? 0
                    ]);
                }
                $shipmentId = $pdo->lastInsertId();
                $createdIds[] = $shipmentId;

                if (!empty($sh['items'])) {
                    $stmt2 = $pdo->prepare("INSERT INTO shipment_items (shipment_id, name, quantity, purchase_price) VALUES (?, ?, ?, ?)");
                    $total = 0;
                    foreach ($sh['items'] as $item) {
                        $stmt2->execute([$shipmentId, $item['name'], $item['quantity'], $item['purchase_price']]);
                        $total += ($item['quantity'] ?? 1) * ($item['purchase_price'] ?? 0);
                    }
                    $total += floatval($sh['carrier_cost'] ?? 0);

                    if (!empty($sh['account_id']) && $hasBankOps) {
                        $pdo->prepare("INSERT INTO bank_operations (account_id, type, amount, description, operation_date, shipment_id, status) VALUES (?, 'Закупка', ?, ?, ?, ?, 'pending')")
                            ->execute([$sh['account_id'], $total, 'Поставка #'.$shipmentId, $sh['order_date'], $shipmentId]);
                    }
                }

                if (($sh['status'] ?? '') === 'Завершено') moveToWarehouse($pdo, $shipmentId);
            }
            $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                apiError('Не удалось создать поставки', $e);
            }

            echo json_encode(['success' => true, 'ids' => $createdIds]);
            break;

        case 'PUT':
            $data = json_decode(file_get_contents('php://input'), true);

            // Калькулятор прибыли: точечное обновление плановой цены продажи товара
            if (isset($data['set_planned']) && isset($data['item_id'])) {
                $val = ($data['planned_sale_price'] === '' || $data['planned_sale_price'] === null)
                    ? null : (float)$data['planned_sale_price'];
                $pdo->prepare("UPDATE shipment_items SET planned_sale_price=? WHERE id=?")
                    ->execute([$val, $data['item_id']]);
                echo json_encode(['success' => true]);
                break;
            }

            // Быстрое обновление статуса
            if (isset($data['status']) && isset($data['id']) && !isset($data['order_date'])) {
                $prevStmt = $pdo->prepare("SELECT status FROM shipments WHERE id=?");
                $prevStmt->execute([$data['id']]);
                $prevStatus = $prevStmt->fetchColumn();

                $pdo->prepare("UPDATE shipments SET status=? WHERE id=?")->execute([$data['status'], $data['id']]);
                if ($data['status'] === 'Завершено') moveToWarehouse($pdo, $data['id']);
                if ($prevStatus === 'Завершено' && $data['status'] !== 'Завершено') revertFromWarehouse($pdo, $data['id']);
                echo json_encode(['success' => true]);
                break;
            }

            // Полное обновление — учитываем наличие колонки account_id
            $prevStmt3 = $pdo->prepare("SELECT status FROM shipments WHERE id=?");
            $prevStmt3->execute([$data['id']]);
            $prevStatusFull = $prevStmt3->fetchColumn();

            $hasAccountId = columnExists($pdo, 'shipments', 'account_id');
            $hasBankOps   = tableExists($pdo, 'bank_operations');
            if ($hasAccountId) {
                $pdo->prepare("UPDATE shipments SET name=?, order_date=?, eta=?, counterparty_id=?, carrier_id=?, tracking=?, status=?, carrier_cost=?, account_id=? WHERE id=?")
                    ->execute([
                        !empty($data['name']) ? $data['name'] : null,
                        $data['order_date'],
                        !empty($data['eta']) ? $data['eta'] : null,
                        !empty($data['counterparty_id']) ? $data['counterparty_id'] : null,
                        !empty($data['carrier_id']) ? $data['carrier_id'] : null,
                        $data['tracking'] ?? '',
                        $data['status'],
                        $data['carrier_cost'] ?? 0,
                        !empty($data['account_id']) ? $data['account_id'] : null,
                        $data['id']
                    ]);
            } else {
                $pdo->prepare("UPDATE shipments SET name=?, order_date=?, eta=?, counterparty_id=?, carrier_id=?, tracking=?, status=?, carrier_cost=? WHERE id=?")
                    ->execute([
                        !empty($data['name']) ? $data['name'] : null,
                        $data['order_date'],
                        !empty($data['eta']) ? $data['eta'] : null,
                        !empty($data['counterparty_id']) ? $data['counterparty_id'] : null,
                        !empty($data['carrier_id']) ? $data['carrier_id'] : null,
                        $data['tracking'] ?? '',
                        $data['status'],
                        $data['carrier_cost'] ?? 0,
                        $data['id']
                    ]);
            }

            if (isset($data['items'])) {
                $check = $pdo->prepare("SELECT COUNT(*) FROM warehouse WHERE shipment_id=?");
                $check->execute([$data['id']]);
                $isOnWarehouse = $check->fetchColumn() > 0;

                if (!$isOnWarehouse) {
                    // Сохраняем плановые цены продажи (калькулятор) — пересоздание строк их не должно стирать
                    $plannedMap = [];
                    if (columnExists($pdo, 'shipment_items', 'planned_sale_price')) {
                        $pm = $pdo->prepare("SELECT name, planned_sale_price FROM shipment_items WHERE shipment_id=?");
                        $pm->execute([$data['id']]);
                        $plannedMap = $pm->fetchAll(PDO::FETCH_KEY_PAIR);
                    }
                    $pdo->prepare("DELETE FROM shipment_items WHERE shipment_id=?")->execute([$data['id']]);
                    $stmt2 = $pdo->prepare("INSERT INTO shipment_items (shipment_id, name, quantity, purchase_price, planned_sale_price) VALUES (?, ?, ?, ?, ?)");
                    foreach ($data['items'] as $item) {
                        $stmt2->execute([$data['id'], $item['name'], $item['quantity'], $item['purchase_price'], $plannedMap[$item['name']] ?? null]);
                    }
                } else {
                    foreach ($data['items'] as $item) {
                        $pdo->prepare("UPDATE shipment_items SET purchase_price=?, quantity=? WHERE shipment_id=? AND name=?")
                            ->execute([$item['purchase_price'], $item['quantity'], $data['id'], $item['name']]);
                        // quantity_left = new_qty - already_sold; already_sold = quantity_total - quantity_left
                        $pdo->prepare("UPDATE warehouse SET purchase_price=?, quantity_total=?, quantity_left=GREATEST(0, ? - (quantity_total - quantity_left)), status=CASE WHEN GREATEST(0, ? - (quantity_total - quantity_left)) <= 0 THEN 'Продан' WHEN GREATEST(0, ? - (quantity_total - quantity_left)) < ? THEN 'Частично продан' ELSE 'На складе' END WHERE shipment_id=? AND name=?")
                            ->execute([$item['purchase_price'], $item['quantity'], $item['quantity'], $item['quantity'], $item['quantity'], $item['quantity'], $data['id'], $item['name']]);
                    }
                }
            }

            if ($data['status'] === 'Завершено') moveToWarehouse($pdo, $data['id']);
            if ($prevStatusFull === 'Завершено' && $data['status'] !== 'Завершено') revertFromWarehouse($pdo, $data['id']);

            if ($hasBankOps && !empty($data['account_id'])) {
                $tot = $pdo->prepare("SELECT COALESCE(SUM(quantity*purchase_price),0) FROM shipment_items WHERE shipment_id=?");
                $tot->execute([$data['id']]);
                $total = $tot->fetchColumn() + floatval($data['carrier_cost'] ?? 0);
                $ex = $pdo->prepare("SELECT id FROM bank_operations WHERE shipment_id=?");
                $ex->execute([$data['id']]);
                if ($ex->fetchColumn()) {
                    $pdo->prepare("UPDATE bank_operations SET account_id=?, amount=? WHERE shipment_id=?")->execute([$data['account_id'], $total, $data['id']]);
                } else {
                    $pdo->prepare("INSERT INTO bank_operations (account_id, type, amount, description, operation_date, shipment_id, status) VALUES (?, 'Закупка', ?, ?, ?, ?, 'pending')")
                        ->execute([$data['account_id'], $total, 'Поставка #'.$data['id'], $data['order_date'], $data['id']]);
                }
            } elseif ($hasBankOps) {
                $pdo->prepare("DELETE FROM bank_operations WHERE shipment_id=? AND sale_id IS NULL")->execute([$data['id']]);
            }

            echo json_encode(['success' => true]);
            break;

        case 'DELETE':
            $pdo->beginTransaction();
            try {
                if (tableExists($pdo, 'bank_operations')) {
                    $pdo->prepare("DELETE FROM bank_operations WHERE shipment_id=?")->execute([$_GET['id']]);
                }
                $pdo->prepare("DELETE FROM warehouse WHERE shipment_id=?")->execute([$_GET['id']]);
                $pdo->prepare("DELETE FROM shipments WHERE id=?")->execute([$_GET['id']]);
                $pdo->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $pdo->rollBack();
                apiError('Не удалось удалить поставку', $e);
            }
            break;
    }
} catch (PDOException $e) {
    apiError('Ошибка базы данных', $e);
}

// Откат склада при смене статуса с "Завершено" обратно
function revertFromWarehouse($pdo, $shipmentId) {
    $stmt = $pdo->prepare("SELECT * FROM warehouse WHERE shipment_id=?");
    $stmt->execute([$shipmentId]);
    foreach ($stmt->fetchAll() as $w) {
        $hasSales = $pdo->prepare("SELECT COUNT(*) FROM sale_items WHERE warehouse_id=?");
        $hasSales->execute([$w['id']]);
        if ($hasSales->fetchColumn() > 0) {
            // Есть продажи — оставляем как зарезервировано (товар ещё в пути)
            $pdo->prepare("UPDATE warehouse SET status='Зарезервировано' WHERE id=?")->execute([$w['id']]);
        } else {
            // Продаж нет — удаляем запись полностью
            $pdo->prepare("DELETE FROM warehouse WHERE id=?")->execute([$w['id']]);
        }
    }
}

function moveToWarehouse($pdo, $shipmentId) {
    $stmt = $pdo->prepare("SELECT * FROM shipment_items WHERE shipment_id=?");
    $stmt->execute([$shipmentId]);
    $items = $stmt->fetchAll();

    $findProd   = $pdo->prepare("SELECT id FROM products WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))");
    $insertProd = $pdo->prepare("INSERT INTO products (name, unit) VALUES (?, 'шт')");
    $checkItem  = $pdo->prepare("SELECT * FROM warehouse WHERE shipment_item_id=?");

    foreach ($items as $item) {
        $checkItem->execute([$item['id']]);
        $existing = $checkItem->fetch();

        if ($existing) {
            // Запись уже есть (была создана при предпродаже из поставки "В пути")
            if ($existing['status'] === 'Зарезервировано') {
                $ql = (int)$existing['quantity_left'];
                $qt = (int)$existing['quantity_total'];
                $newStatus = $ql <= 0 ? 'Продан' : ($ql < $qt ? 'Частично продан' : 'На складе');
                $pdo->prepare("UPDATE warehouse SET status=? WHERE id=?")
                    ->execute([$newStatus, $existing['id']]);
            }
            continue;
        }

        // Новый товар — добавляем на склад
        $findProd->execute([$item['name']]);
        $productId = $findProd->fetchColumn();
        if (!$productId) {
            $insertProd->execute([$item['name']]);
            $productId = $pdo->lastInsertId();
        }
        $pdo->prepare("INSERT INTO warehouse (shipment_item_id, shipment_id, name, quantity_total, quantity_left, purchase_price, status, product_id) VALUES (?,?,?,?,?,?,'На складе',?)")
            ->execute([$item['id'], $shipmentId, $item['name'], $item['quantity'], $item['quantity'], $item['purchase_price'], $productId]);
    }
}
