<?php
require_once '../config.php';
session_start();
if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
    http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit;
}
header('Content-Type: application/json');

// Схема таблиц планов закупок создаётся миграцией database/migrate_procurement.php
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {

        // ── GET ──────────────────────────────────────────────────────────────
        case 'GET':
            if (isset($_GET['plans'])) {
                $rows = $pdo->query("
                    SELECT p.*, COUNT(DISTINCT pi.id) as items_count
                    FROM procurement_plans p
                    LEFT JOIN plan_items pi ON pi.plan_id = p.id
                    GROUP BY p.id
                    ORDER BY p.created_at DESC
                ")->fetchAll();
                echo json_encode($rows);
                break;
            }

            if (isset($_GET['plan_id'])) {
                $planId = (int)$_GET['plan_id'];

                $stmtPlan = $pdo->prepare("SELECT * FROM procurement_plans WHERE id = ?");
                $stmtPlan->execute([$planId]);
                $plan = $stmtPlan->fetch();
                if (!$plan) { echo json_encode(['error' => 'Plan not found']); break; }

                $stmtItems = $pdo->prepare("SELECT * FROM plan_items WHERE plan_id = ?");
                $stmtItems->execute([$planId]);
                $items = $stmtItems->fetchAll();

                // Загружаем все варианты одним запросом (без N+1)
                $variantsByItem = [];
                $itemIds = array_column($items, 'id');
                if (!empty($itemIds)) {
                    $in = implode(',', array_fill(0, count($itemIds), '?'));
                    $stmtVariants = $pdo->prepare("SELECT * FROM plan_variants WHERE item_id IN ($in)");
                    $stmtVariants->execute($itemIds);
                    foreach ($stmtVariants->fetchAll() as $v) {
                        $variantsByItem[$v['item_id']][] = $v;
                    }
                }
                foreach ($items as &$item) {
                    $item['variants'] = $variantsByItem[$item['id']] ?? [];
                }
                unset($item);

                echo json_encode(['plan' => $plan, 'items' => $items]);
                break;
            }

            echo json_encode(['error' => 'Bad request']);
            break;

        // ── POST ─────────────────────────────────────────────────────────────
        case 'POST':
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $action = $body['action'] ?? '';

            if ($action === 'create_plan') {
                $name   = trim($body['name'] ?? '');
                $status = $body['status'] ?? 'В работе';
                if ($name === '') { echo json_encode(['error' => 'Name required']); break; }
                $stmt = $pdo->prepare("INSERT INTO procurement_plans (name, status) VALUES (?, ?)");
                $stmt->execute([$name, $status]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
                break;
            }

            if ($action === 'add_item') {
                $planId   = (int)($body['plan_id'] ?? 0);
                $name     = trim($body['name'] ?? '');
                $quantity = (int)($body['quantity'] ?? 1);
                if (!$planId || $name === '') { echo json_encode(['error' => 'plan_id and name required']); break; }
                $stmt = $pdo->prepare("INSERT INTO plan_items (plan_id, name, quantity) VALUES (?, ?, ?)");
                $stmt->execute([$planId, $name, $quantity]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
                break;
            }

            if ($action === 'add_variant') {
                $itemId       = (int)($body['item_id'] ?? 0);
                $supplierName = trim($body['supplier_name'] ?? '');
                $price        = (float)($body['price'] ?? 0);
                $deliveryDays = isset($body['delivery_days']) && $body['delivery_days'] !== '' ? (int)$body['delivery_days'] : null;
                $carrierCost  = isset($body['carrier_cost']) && $body['carrier_cost'] !== '' && $body['carrier_cost'] !== null ? (float)$body['carrier_cost'] : null;
                $link         = trim($body['link'] ?? '');
                $notes        = trim($body['notes'] ?? '');
                if (!$itemId) { echo json_encode(['error' => 'item_id required']); break; }
                $stmt = $pdo->prepare("INSERT INTO plan_variants (item_id, supplier_name, price, delivery_days, carrier_cost, link, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$itemId, $supplierName, $price, $deliveryDays, $carrierCost, $link, $notes]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
                break;
            }

            if ($action === 'select_winner') {
                $itemId    = (int)($body['item_id'] ?? 0);
                $variantId = (int)($body['variant_id'] ?? 0);
                if (!$itemId || !$variantId) { echo json_encode(['error' => 'item_id and variant_id required']); break; }
                // Победитель должен быть вариантом именно этой позиции
                $chk = $pdo->prepare("SELECT COUNT(*) FROM plan_variants WHERE id = ? AND item_id = ?");
                $chk->execute([$variantId, $itemId]);
                if (!$chk->fetchColumn()) {
                    echo json_encode(['error' => 'Вариант не принадлежит этой позиции плана']);
                    break;
                }
                $stmt = $pdo->prepare("UPDATE plan_items SET winner_variant_id = ? WHERE id = ?");
                $stmt->execute([$variantId, $itemId]);
                echo json_encode(['success' => true]);
                break;
            }

            if ($action === 'create_shipments_from_plan') {
                $planId = (int)($body['plan_id'] ?? 0);
                if (!$planId) { echo json_encode(['error' => 'plan_id required']); break; }

                $stmtPlan = $pdo->prepare("SELECT * FROM procurement_plans WHERE id = ?");
                $stmtPlan->execute([$planId]);
                $plan = $stmtPlan->fetch();
                if (!$plan) { echo json_encode(['error' => 'Plan not found']); break; }

                // Позиции с корректным победителем (вариант принадлежит этой же позиции)
                $stmt = $pdo->prepare("
                    SELECT pi.*, pv.supplier_name, pv.price as variant_price
                    FROM plan_items pi
                    INNER JOIN plan_variants pv ON pv.id = pi.winner_variant_id AND pv.item_id = pi.id
                    WHERE pi.plan_id = ? AND pi.winner_variant_id IS NOT NULL
                ");
                $stmt->execute([$planId]);
                $winnerItems = $stmt->fetchAll();

                if (empty($winnerItems)) {
                    echo json_encode(['error' => 'No items with selected winners']);
                    break;
                }

                // Сколько позиций с победителем оказалось некорректными (пропущены)
                $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM plan_items WHERE plan_id = ? AND winner_variant_id IS NOT NULL");
                $cntStmt->execute([$planId]);
                $skippedWinners = (int)$cntStmt->fetchColumn() - count($winnerItems);

                // Group by supplier_name
                $bySupplier = [];
                foreach ($winnerItems as $item) {
                    $sup = $item['supplier_name'] ?? '';
                    $bySupplier[$sup][] = $item;
                }

                $shipmentIds = [];
                $stmtCP = $pdo->prepare("SELECT id FROM counterparties WHERE name = ? LIMIT 1");
                $stmtShipment = $pdo->prepare("INSERT INTO shipments (name, order_date, status, counterparty_id) VALUES (?, CURDATE(), ?, ?)");
                $stmtShipItem = $pdo->prepare("INSERT INTO shipment_items (shipment_id, name, quantity, purchase_price) VALUES (?, ?, ?, ?)");

                // Всё в одной транзакции — либо все поставки, либо ни одной
                $pdo->beginTransaction();
                try {
                    foreach ($bySupplier as $supplierName => $items) {
                        // Look up counterparty
                        $stmtCP->execute([$supplierName]);
                        $cp = $stmtCP->fetch();
                        $cpId = $cp ? $cp['id'] : null;

                        $shipName = 'Из плана: ' . $plan['name'];
                        $stmtShipment->execute([$shipName, 'Ожидает отправки', $cpId]);
                        $shipmentId = $pdo->lastInsertId();
                        $shipmentIds[] = (int)$shipmentId;

                        foreach ($items as $item) {
                            $stmtShipItem->execute([$shipmentId, $item['name'], $item['quantity'], $item['variant_price']]);
                        }
                    }

                    // Mark plan as executed
                    $pdo->prepare("UPDATE procurement_plans SET status = 'Исполнен' WHERE id = ?")->execute([$planId]);
                    $pdo->commit();
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    apiError('Не удалось создать поставки из плана', $e);
                }

                $resp = ['success' => true, 'shipment_ids' => $shipmentIds];
                if ($skippedWinners > 0) {
                    $resp['warning'] = "Пропущено позиций с некорректным победителем: {$skippedWinners}";
                }
                echo json_encode($resp);
                break;
            }

            echo json_encode(['error' => 'Unknown action']);
            break;

        // ── PUT ──────────────────────────────────────────────────────────────
        case 'PUT':
            $body = json_decode(file_get_contents('php://input'), true) ?? [];

            if (isset($body['plan_id']) && isset($body['status'])) {
                $stmt = $pdo->prepare("UPDATE procurement_plans SET status = ? WHERE id = ?");
                $stmt->execute([$body['status'], (int)$body['plan_id']]);
                echo json_encode(['success' => true]);
                break;
            }

            if (isset($body['plan_id']) && isset($body['name'])) {
                $stmt = $pdo->prepare("UPDATE procurement_plans SET name = ? WHERE id = ?");
                $stmt->execute([trim($body['name']), (int)$body['plan_id']]);
                echo json_encode(['success' => true]);
                break;
            }

            if (isset($body['item_id']) && isset($body['quantity'])) {
                $stmt = $pdo->prepare("UPDATE plan_items SET quantity = ? WHERE id = ?");
                $stmt->execute([(int)$body['quantity'], (int)$body['item_id']]);
                echo json_encode(['success' => true]);
                break;
            }

            if (isset($body['variant_id'])) {
                $variantId = (int)$body['variant_id'];
                $fields = [];
                $params = [];
                $allowed = ['supplier_name', 'price', 'delivery_days', 'min_qty', 'payment_terms', 'link', 'notes', 'carrier_cost'];
                foreach ($allowed as $f) {
                    if (array_key_exists($f, $body)) {
                        $fields[] = "$f = ?";
                        $params[] = ($body[$f] !== '' && $body[$f] !== null) ? $body[$f] : null;
                    }
                }
                if (empty($fields)) { echo json_encode(['error' => 'No fields to update']); break; }
                $params[] = $variantId;
                $pdo->prepare("UPDATE plan_variants SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
                echo json_encode(['success' => true]);
                break;
            }

            if (isset($body['item_id']) && isset($body['name'])) {
                $stmt = $pdo->prepare("UPDATE plan_items SET name = ?, quantity = ? WHERE id = ?");
                $stmt->execute([trim($body['name']), (int)($body['quantity']??1), (int)$body['item_id']]);
                echo json_encode(['success' => true]);
                break;
            }

            echo json_encode(['error' => 'Bad PUT request']);
            break;

        // ── DELETE ────────────────────────────────────────────────────────────
        case 'DELETE':
            if (isset($_GET['plan_id'])) {
                $planId = (int)$_GET['plan_id'];
                // Get all item ids
                $items = $pdo->prepare("SELECT id FROM plan_items WHERE plan_id = ?");
                $items->execute([$planId]);
                $itemIds = array_column($items->fetchAll(), 'id');
                if (!empty($itemIds)) {
                    $in = implode(',', array_fill(0, count($itemIds), '?'));
                    $pdo->prepare("DELETE FROM plan_variants WHERE item_id IN ($in)")->execute($itemIds);
                }
                $pdo->prepare("DELETE FROM plan_items WHERE plan_id = ?")->execute([$planId]);
                $pdo->prepare("DELETE FROM procurement_plans WHERE id = ?")->execute([$planId]);
                echo json_encode(['success' => true]);
                break;
            }

            if (isset($_GET['item_id'])) {
                $itemId = (int)$_GET['item_id'];
                $pdo->prepare("DELETE FROM plan_variants WHERE item_id = ?")->execute([$itemId]);
                $pdo->prepare("DELETE FROM plan_items WHERE id = ?")->execute([$itemId]);
                echo json_encode(['success' => true]);
                break;
            }

            if (isset($_GET['variant_id'])) {
                $pdo->prepare("DELETE FROM plan_variants WHERE id = ?")->execute([(int)$_GET['variant_id']]);
                echo json_encode(['success' => true]);
                break;
            }

            echo json_encode(['error' => 'Bad DELETE request']);
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    apiError('Ошибка базы данных', $e);
}
