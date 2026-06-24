<?php
require_once '../config.php';
session_start();
if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

// Миграция: колонка source_shipment_id для продаж из поставок "В пути"
try { $pdo->exec("ALTER TABLE sales ADD COLUMN source_shipment_id INT DEFAULT NULL"); } catch (PDOException $e) {}

/**
 * Резервирует позицию поставки «В пути» под продажу: создаёт (или переиспользует)
 * складскую строку со статусом «Зарезервировано» и списывает остаток.
 * Возвращает ['warehouse_id'=>int] или ['error'=>string]. Остаток уже списан.
 */
function reserveShipmentItem(PDO $pdo, int $siId, int $qty): array {
    $siStmt = $pdo->prepare("SELECT * FROM shipment_items WHERE id = ?");
    $siStmt->execute([$siId]);
    $si = $siStmt->fetch();
    if (!$si) return ['error' => 'Позиция поставки не найдена'];

    $checkW = $pdo->prepare("SELECT * FROM warehouse WHERE shipment_item_id = ? FOR UPDATE");
    $checkW->execute([$siId]);
    $wRow = $checkW->fetch();

    if ($wRow) {
        if ($qty > $wRow['quantity_left']) return ['error' => 'Недостаточно остатка: ' . $si['name']];
        $pdo->prepare("UPDATE warehouse SET quantity_left = ? WHERE id = ?")
            ->execute([$wRow['quantity_left'] - $qty, $wRow['id']]);
        return ['warehouse_id' => (int)$wRow['id']];
    }

    if ($qty > $si['quantity']) return ['error' => 'Недостаточно остатка: ' . $si['name']];

    $findProd = $pdo->prepare("SELECT id FROM products WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))");
    $findProd->execute([$si['name']]);
    $productId = $findProd->fetchColumn();
    if (!$productId) {
        $pdo->prepare("INSERT INTO products (name, unit) VALUES (?, 'шт')")->execute([$si['name']]);
        $productId = $pdo->lastInsertId();
    }
    $pdo->prepare("INSERT INTO warehouse (shipment_item_id, shipment_id, name, quantity_total, quantity_left, purchase_price, status, product_id) VALUES (?,?,?,?,?,?,'Зарезервировано',?)")
        ->execute([$siId, $si['shipment_id'], $si['name'], $si['quantity'], $si['quantity'] - $qty, $si['purchase_price'], $productId]);
    return ['warehouse_id' => (int)$pdo->lastInsertId()];
}

try {
switch ($method) {

    case 'GET':
    // Одна запись по id
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("
            SELECT s.*, COALESCE(c.company_type,'') as company_type,
                COALESCE(c.name, s.buyer_name, '—') as counterparty
            FROM sales s LEFT JOIN counterparties c ON c.id = s.counterparty_id
            WHERE s.id = ?");
        $stmt->execute([$_GET['id']]);
        $sale = $stmt->fetch();
        if ($sale) {
            $si = $pdo->prepare("SELECT si.*, w.name as product_name FROM sale_items si LEFT JOIN warehouse w ON w.id = si.warehouse_id WHERE si.sale_id = ?");
            $si->execute([$sale['id']]);
            $sale['items'] = $si->fetchAll();
        }
        echo json_encode($sale ?: null);
        break;
    }

    $sales = $pdo->query("
        SELECT s.*,
            COALESCE(c.company_type, '') as company_type,
            COALESCE(c.name, s.buyer_name, '—') as counterparty
        FROM sales s
        LEFT JOIN counterparties c ON c.id = s.counterparty_id
        ORDER BY s.sale_date DESC
    ")->fetchAll();

    if ($sales) {
        $ids = array_column($sales, 'id');
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $aiStmt = $pdo->prepare("
            SELECT si.*, w.name as product_name, w.purchase_price, si.sale_price
            FROM sale_items si
            LEFT JOIN warehouse w ON w.id = si.warehouse_id
            WHERE si.sale_id IN ($ph)
        ");
        $aiStmt->execute($ids);
        $allItems = $aiStmt->fetchAll();

        $itemsBySale = [];
        foreach ($allItems as $item) {
            $itemsBySale[$item['sale_id']][] = $item;
        }
        foreach ($sales as &$sale) {
            $sale['items'] = $itemsBySale[$sale['id']] ?? [];
        }
    }

    echo json_encode($sales);
    break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);

        // ── Продажа из поставки "В пути" ──────────────────────────────────────
        if (($data['action'] ?? '') === 'from_shipment') {
            $shipmentId     = (int)$data['shipment_id'];
            $inItems        = $data['items'] ?? [];
            $counterpartyId = !empty($data['counterparty_id']) ? (int)$data['counterparty_id'] : null;
            $buyerName      = $counterpartyId ? null : (trim($data['buyer_name'] ?? '') ?: null);
            $status         = $data['status']    ?? 'Счёт выставлен';
            $saleDate       = $data['sale_date'] ?? date('Y-m-d');
            $accountId      = !empty($data['account_id']) ? (int)$data['account_id'] : null;
            $acquiringPct   = isset($data['acquiring_pct']) ? floatval($data['acquiring_pct']) : 0;

            if (empty($inItems)) { echo json_encode(['error' => 'Выберите товары']); break; }

            $pdo->beginTransaction();
            try {
                $siStmt   = $pdo->prepare("SELECT * FROM shipment_items WHERE id=? AND shipment_id=?");
                $checkW   = $pdo->prepare("SELECT * FROM warehouse WHERE shipment_item_id=?");
                $findProd = $pdo->prepare("SELECT id FROM products WHERE LOWER(TRIM(name))=LOWER(TRIM(?))");

                $totalPrice = 0;
                $wData      = [];

                foreach ($inItems as $item) {
                    $siId  = (int)$item['shipment_item_id'];
                    $qty   = (int)($item['quantity']   ?? 0);
                    $price = floatval($item['sale_price'] ?? 0);
                    if ($qty <= 0) continue;

                    $siStmt->execute([$siId, $shipmentId]);
                    $si = $siStmt->fetch();
                    if (!$si) continue;

                    $checkW->execute([$siId]);
                    $wRow = $checkW->fetch();

                    if ($wRow) {
                        // Уже есть запись (повторная частичная продажа из той же поставки)
                        if ($wRow['quantity_left'] < $qty) {
                            $pdo->rollBack();
                            echo json_encode(['error' => 'Недостаточно остатка: ' . $si['name']]);
                            exit;
                        }
                        $newLeft = $wRow['quantity_left'] - $qty;
                        $pdo->prepare("UPDATE warehouse SET quantity_left=? WHERE id=?")
                            ->execute([$newLeft, $wRow['id']]);
                        $warehouseId = $wRow['id'];
                    } else {
                        // Создаём warehouse-запись "Зарезервировано"
                        $findProd->execute([$si['name']]);
                        $productId = $findProd->fetchColumn();
                        if (!$productId) {
                            $pdo->prepare("INSERT INTO products (name, unit) VALUES (?, 'шт')")->execute([$si['name']]);
                            $productId = $pdo->lastInsertId();
                        }
                        $newLeft = $si['quantity'] - $qty;
                        $pdo->prepare("INSERT INTO warehouse (shipment_item_id, shipment_id, name, quantity_total, quantity_left, purchase_price, status, product_id) VALUES (?,?,?,?,?,?,'Зарезервировано',?)")
                            ->execute([$siId, $shipmentId, $si['name'], $si['quantity'], $newLeft, $si['purchase_price'], $productId]);
                        $warehouseId = $pdo->lastInsertId();
                    }

                    $wData[] = ['warehouse_id' => $warehouseId, 'quantity' => $qty, 'sale_price' => $price];
                    $totalPrice += $price * $qty;
                }

                if (empty($wData)) {
                    $pdo->rollBack();
                    echo json_encode(['error' => 'Укажите количество хотя бы для одного товара']);
                    break;
                }

                $pdo->prepare("INSERT INTO sales (counterparty_id, buyer_name, sale_price, sale_date, status, account_id, source_shipment_id) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$counterpartyId, $buyerName, $totalPrice, $saleDate, $status, $accountId, $shipmentId]);
                $saleId = $pdo->lastInsertId();

                foreach ($wData as $wd) {
                    $pdo->prepare("INSERT INTO sale_items (sale_id, warehouse_id, quantity, sale_price) VALUES (?,?,?,?)")
                        ->execute([$saleId, $wd['warehouse_id'], $wd['quantity'], $wd['sale_price']]);
                }

                // Банковская операция (та же логика что в обычной продаже)
                if ($accountId && $status === 'Оплачено') {
                    $isCash = !$counterpartyId && $buyerName !== null;
                    $gross  = $totalPrice;
                    if ($acquiringPct > 0) {
                        $fee    = round($gross * $acquiringPct / 100, 2);
                        $amount = round($gross - $fee, 2);
                        $opDesc = "Продажа по кассе (эквайринг {$acquiringPct}%, комиссия {$fee} ₽)";
                        $opType = $isCash ? 'Прочий приход' : 'Продажа';
                    } else {
                        $amount = $gross;
                        $opDesc = $isCash ? 'Продажа по кассе' : 'Продажа';
                        $opType = $isCash ? 'Прочий приход' : 'Продажа';
                    }
                    $pdo->prepare("INSERT INTO bank_operations (account_id, type, amount, description, operation_date, sale_id, status) VALUES (?,?,?,?,?,?,'confirmed')")
                        ->execute([$accountId, $opType, $amount, $opDesc, $saleDate, $saleId]);
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'sale_id' => $saleId]);
            } catch (\Throwable $e) {
                $pdo->rollBack();
                apiError('Ошибка при создании продажи: ' . $e->getMessage());
            }
            break;
        }
        // ── /from_shipment ─────────────────────────────────────────────────────

        if (empty($data['items'])) { echo json_encode(['error' => 'Выберите товары']); break; }
        if (empty($data['sale_price']) || $data['sale_price'] <= 0) { echo json_encode(['error' => 'Укажите сумму продажи']); break; }
        if (empty($data['sale_date'])) { echo json_encode(['error' => 'Укажите дату']); break; }

        // Покупатель: контрагент из справочника ИЛИ свободное имя (касса/розница), но не оба.
        $counterpartyId = !empty($data['counterparty_id']) ? (int)$data['counterparty_id'] : null;
        $buyerName      = $counterpartyId ? null : (trim($data['buyer_name'] ?? '') ?: null);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                INSERT INTO sales (counterparty_id, buyer_name, sale_price, sale_date, account_id, status)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $counterpartyId,
                $buyerName,
                $data['sale_price'],
                $data['sale_date'],
                $data['account_id'] ?? null,
                $data['status'] ?? 'Оплачено'
            ]);
            $saleId = $pdo->lastInsertId();

            foreach ($data['items'] as $item) {
                $qty = intval($item['quantity'] ?? 1);
                if ($qty <= 0) continue;

                // Товар «в пути»: резервируем позицию поставки (создаём строку
                // «Зарезервировано»). Остаток списывается внутри хелпера, поэтому
                // обычную ветку склада пропускаем.
                if (!empty($item['shipment_item_id'])) {
                    $res = reserveShipmentItem($pdo, (int)$item['shipment_item_id'], $qty);
                    if (isset($res['error'])) {
                        $pdo->rollBack();
                        echo json_encode(['error' => $res['error']]);
                        exit;
                    }
                    $pdo->prepare("INSERT INTO sale_items (sale_id, warehouse_id, quantity, sale_price) VALUES (?, ?, ?, ?)")
                        ->execute([$saleId, $res['warehouse_id'], $qty, floatval($item['sale_price'] ?? 0)]);
                    continue;
                }

                $warehouseId = $item['warehouse_id'];

                $wStmt = $pdo->prepare("SELECT * FROM warehouse WHERE id = ? FOR UPDATE");
                $wStmt->execute([$warehouseId]);
                $wItem = $wStmt->fetch();

                // Нехватка остатка — откатываем всю продажу с ошибкой,
                // иначе деньги запишутся без списания товара
                if (!$wItem) {
                    $pdo->rollBack();
                    echo json_encode(['error' => 'Товар не найден на складе (id ' . (int)$warehouseId . ')']);
                    exit;
                }
                if ($qty > $wItem['quantity_left']) {
                    $pdo->rollBack();
                    echo json_encode(['error' => 'Недостаточно остатка: ' . $wItem['name'] . ' (есть ' . $wItem['quantity_left'] . ', нужно ' . $qty . ')']);
                    exit;
                }

                $pdo->prepare("INSERT INTO sale_items (sale_id, warehouse_id, quantity, sale_price) VALUES (?, ?, ?, ?)")
                    ->execute([$saleId, $warehouseId, $qty, floatval($item['sale_price'] ?? 0)]);

                $newQty = $wItem['quantity_left'] - $qty;
                $newStatus = $newQty <= 0 ? 'Продан' : ($newQty < $wItem['quantity_total'] ? 'Частично продан' : 'На складе');
                $pdo->prepare("UPDATE warehouse SET quantity_left = ?, status = ? WHERE id = ?")
                    ->execute([$newQty, $newStatus, $warehouseId]);
            }

            if (!empty($data['account_id']) && ($data['status'] ?? 'Оплачено') === 'Оплачено') {
                $isCash = !$counterpartyId && $buyerName !== null;

                $gross = floatval($data['sale_price']);
                $pct   = isset($data['acquiring_pct']) ? floatval($data['acquiring_pct']) : 0;
                if ($pct > 0) {
                    $fee    = round($gross * $pct / 100, 2);
                    $amount = round($gross - $fee, 2);
                    $opDescription = "Продажа по кассе (эквайринг {$pct}%, комиссия {$fee} ₽)";
                    // Тип сохраняем как в оригинале: free-text-покупатель → Прочий приход, DB-контрагент → Продажа
                    $opType = $isCash ? 'Прочий приход' : 'Продажа';
                } else {
                    $amount = $gross;
                    $opDescription = $isCash ? 'Продажа по кассе' : 'Продажа';
                    $opType = $isCash ? 'Прочий приход' : 'Продажа';
                }

                $pdo->prepare("
                    INSERT INTO bank_operations (account_id, type, amount, description, operation_date, sale_id, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending')
                ")->execute([$data['account_id'], $opType, $amount, $opDescription, $data['sale_date'], $saleId]);
            }

            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);

        // Покупатель: контрагент ИЛИ свободное имя (касса), но не оба
        $counterpartyId = !empty($data['counterparty_id']) ? (int)$data['counterparty_id'] : null;
        $buyerName      = $counterpartyId ? null : (trim($data['buyer_name'] ?? '') ?: null);

        $pdo->beginTransaction();
        try {
            // 1. Возвращаем ВСЕ текущие товары продажи обратно на склад
            $oldItems = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
            $oldItems->execute([$data['id']]);
            foreach ($oldItems->fetchAll() as $old) {
                $w = $pdo->prepare("SELECT * FROM warehouse WHERE id = ? FOR UPDATE");
                $w->execute([$old['warehouse_id']]);
                $wRow = $w->fetch();
                if (!$wRow) continue;
                $restoredQty = $wRow['quantity_left'] + $old['quantity'];
                if ($wRow['status'] === 'Зарезервировано') {
                    // Товар в пути — не переводить в "На складе"
                    if ($restoredQty >= $wRow['quantity_total']) {
                        $pdo->prepare("DELETE FROM warehouse WHERE id = ?")->execute([$wRow['id']]);
                    } else {
                        $pdo->prepare("UPDATE warehouse SET quantity_left = ? WHERE id = ?")
                            ->execute([$restoredQty, $wRow['id']]);
                    }
                } else {
                    $newStatus = $restoredQty >= $wRow['quantity_total'] ? 'На складе'
                        : ($restoredQty > 0 ? 'Частично продан' : 'Продан');
                    $pdo->prepare("UPDATE warehouse SET quantity_left = ?, status = ? WHERE id = ?")
                        ->execute([$restoredQty, $newStatus, $wRow['id']]);
                }
            }

            // 2. Удаляем старые sale_items
            $pdo->prepare("DELETE FROM sale_items WHERE sale_id = ?")->execute([$data['id']]);

            // 3. Обновляем основные данные продажи
            $pdo->prepare("
                UPDATE sales
                SET sale_price = ?, sale_date = ?, counterparty_id = ?, buyer_name = ?, status = ?, account_id = ?
                WHERE id = ?
            ")->execute([
                $data['sale_price'],
                $data['sale_date'],
                $counterpartyId,
                $buyerName,
                $data['status'] ?? 'Оплачено',
                ($data['status'] === 'Оплачено') ? ($data['account_id'] ?? null) : null,
                $data['id']
            ]);

            // 4. Добавляем новые товары и списываем со склада
            foreach (($data['items'] ?? []) as $item) {
                $warehouseId = $item['warehouse_id'];
                $qty = intval($item['quantity'] ?? 1);

                $w = $pdo->prepare("SELECT * FROM warehouse WHERE id = ? FOR UPDATE");
                $w->execute([$warehouseId]);
                $wRow = $w->fetch();

                if (!$wRow || $qty > $wRow['quantity_left']) continue;

                $pdo->prepare("INSERT INTO sale_items (sale_id, warehouse_id, quantity, sale_price) VALUES (?, ?, ?, ?)")
                    ->execute([$data['id'], $warehouseId, $qty, floatval($item['sale_price'] ?? 0)]);

                $newQty = $wRow['quantity_left'] - $qty;
                $newStatus = $newQty <= 0 ? 'Продан' : ($newQty < $wRow['quantity_total'] ? 'Частично продан' : 'На складе');
                $pdo->prepare("UPDATE warehouse SET quantity_left = ?, status = ? WHERE id = ?")
                    ->execute([$newQty, $newStatus, $warehouseId]);
            }

            // 5. Обновляем банковскую операцию
            $pdo->prepare("DELETE FROM bank_operations WHERE sale_id = ?")->execute([$data['id']]);
            if (!empty($data['account_id']) && ($data['status'] ?? '') === 'Оплачено') {
                $saleStmt = $pdo->prepare("SELECT * FROM sales WHERE id = ?");
                $saleStmt->execute([$data['id']]);
                $saleRow = $saleStmt->fetch();

                $isCash = !$counterpartyId && $buyerName !== null;

                $gross = floatval($saleRow['sale_price']);
                $pct   = isset($data['acquiring_pct']) ? floatval($data['acquiring_pct']) : 0;
                if ($pct > 0) {
                    $fee    = round($gross * $pct / 100, 2);
                    $amount = round($gross - $fee, 2);
                    $cpDesc = "Продажа по кассе (эквайринг {$pct}%, комиссия {$fee} ₽)";
                    $opType = $isCash ? 'Прочий приход' : 'Продажа';
                } else {
                    $amount = $gross;
                    $cpDesc = $isCash ? 'Продажа по кассе' : 'Продажа';
                    $opType = $isCash ? 'Прочий приход' : 'Продажа';
                }

                $pdo->prepare("
                    INSERT INTO bank_operations (account_id, type, amount, description, operation_date, sale_id, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'pending')
                ")->execute([$data['account_id'], $opType, $amount, $cpDesc, $saleRow['sale_date'], $data['id']]);
            }

            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;

    case 'DELETE':
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM sale_items WHERE sale_id = ?");
            $stmt->execute([$_GET['id']]);
            foreach ($stmt->fetchAll() as $item) {
                $wRow = $pdo->prepare("SELECT * FROM warehouse WHERE id = ? FOR UPDATE");
                $wRow->execute([$item['warehouse_id']]);
                $w = $wRow->fetch();
                if (!$w) continue;
                $restoredQty = $w['quantity_left'] + $item['quantity'];
                if ($w['status'] === 'Зарезервировано') {
                    // Товар ещё в пути: при полном восстановлении удаляем запись, иначе сохраняем резерв
                    if ($restoredQty >= $w['quantity_total']) {
                        $pdo->prepare("DELETE FROM warehouse WHERE id = ?")->execute([$w['id']]);
                    } else {
                        $pdo->prepare("UPDATE warehouse SET quantity_left = ? WHERE id = ?")
                            ->execute([$restoredQty, $w['id']]);
                    }
                } else {
                    $newStatus = $restoredQty >= $w['quantity_total'] ? 'На складе' : 'Частично продан';
                    $pdo->prepare("UPDATE warehouse SET quantity_left = ?, status = ? WHERE id = ?")
                        ->execute([$restoredQty, $newStatus, $w['id']]);
                }
            }
            $pdo->prepare("DELETE FROM bank_operations WHERE sale_id = ?")->execute([$_GET['id']]);
            $pdo->prepare("DELETE FROM sales WHERE id = ?")->execute([$_GET['id']]);
            $pdo->commit();
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['error' => $e->getMessage()]);
        }
        break;
}
} catch (PDOException $e) {
    echo json_encode(["error" => $e->getMessage()]);
}