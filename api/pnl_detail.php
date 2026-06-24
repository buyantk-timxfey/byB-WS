<?php
require_once '../config.php';
session_start();
if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
header('Content-Type: application/json');

$type  = $_GET['type']  ?? '';
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : null;
$month = isset($_GET['month']) ? (int)$_GET['month'] : null;

// ── Хелпер: условие фильтра по году/месяцу ──────────────────────────────────
function ymWhere(string $dateCol, ?int $year, ?int $month): string {
    if ($year && $month) return " AND YEAR({$dateCol}) = {$year} AND MONTH({$dateCol}) = {$month}";
    if ($year)           return " AND YEAR({$dateCol}) = {$year}";
    return '';
}

// ── Единый хелпер расчёта закупок (скалярный итог) ──────────────────────────
// Используется в tax / margin / roi.
// Паттерн: подзапрос группирует shipment_items → один ряд на поставку → нет GROUP BY снаружи.
function purchaseTotalSql(?int $year, ?int $month): string {
    $ym = ymWhere('sh.order_date', $year, $month);
    return "
        SELECT COALESCE(SUM(si.items_cost), 0) + COALESCE(SUM(sh.carrier_cost), 0) AS v
        FROM shipments sh
        LEFT JOIN (
            SELECT shipment_id, SUM(quantity * purchase_price) AS items_cost
            FROM shipment_items
            GROUP BY shipment_id
        ) si ON si.shipment_id = sh.id
        WHERE sh.status = 'Завершено'{$ym}";
}

// ── Единый хелпер расчёта выручки (скалярный итог) ──────────────────────────
function incomeTotalSql(?int $year, ?int $month): string {
    $ym = ymWhere('sale_date', $year, $month);
    return "SELECT COALESCE(SUM(sale_price), 0) AS v FROM sales WHERE status = 'Оплачено'{$ym}";
}

// ── Единый хелпер расчёта расходов ИП (скалярный итог) ─────────────────────
function bizTotalSql(?int $year, ?int $month): string {
    $ym = ymWhere('expense_date', $year, $month);
    return "SELECT COALESCE(SUM(amount), 0) AS v FROM business_expenses WHERE 1=1{$ym}";
}

try {
    switch ($type) {

        // ════════════════════════════════════════════════════════════════════
        // ВЫРУЧКА — список оплаченных продаж
        // ════════════════════════════════════════════════════════════════════
        case 'income': {
            // ВАЖНО: у таблицы sales НЕТ колонок quantity / warehouse_id.
            // Позиции продажи хранятся в sale_items (sale_id, warehouse_id, quantity).
            // Кол-во и названия товаров агрегируем через подзапрос → совместимо с ONLY_FULL_GROUP_BY.
            $ym  = ymWhere('s.sale_date', $year, $month);
            $sql = "
                SELECT s.id, s.sale_date, s.sale_price, s.status,
                       COALESCE(c.name, '')         AS counterparty,
                       COALESCE(c.company_type, '') AS company_type,
                       s.buyer_name,
                       COALESCE(it.qty, 0)          AS qty,
                       it.product_name
                FROM sales s
                LEFT JOIN counterparties c ON c.id = s.counterparty_id
                LEFT JOIN (
                    SELECT si.sale_id,
                           SUM(si.quantity) AS qty,
                           GROUP_CONCAT(DISTINCT w.name SEPARATOR ', ') AS product_name
                    FROM sale_items si
                    LEFT JOIN warehouse w ON w.id = si.warehouse_id
                    GROUP BY si.sale_id
                ) it ON it.sale_id = s.id
                WHERE s.status = 'Оплачено'{$ym}
                ORDER BY s.sale_date DESC";

            $rows  = $pdo->query($sql)->fetchAll();
            $total = (float) array_sum(array_column($rows, 'sale_price'));
            $cnt   = count($rows);

            echo json_encode([
                'type'  => 'income',
                'total' => $total,
                'cnt'   => $cnt,
                'rows'  => array_map(fn($r) => [
                    'id'      => (int) $r['id'],
                    'date'    => $r['sale_date'],
                    'buyer'   => $r['counterparty'] ?: ($r['buyer_name'] ?: 'Розница'),
                    'product' => $r['product_name'] ?: '—',
                    'qty'     => (int) $r['qty'],
                    'amount'  => (float) $r['sale_price'],
                    'status'  => $r['status'],
                ], $rows),
            ]);
            break;
        }

        // ════════════════════════════════════════════════════════════════════
        // ЗАКУПКИ — список поставок
        // FIX: подзапрос для items_cost → нет GROUP BY на внешнем запросе
        //      → нет конфликта с ONLY_FULL_GROUP_BY
        // ════════════════════════════════════════════════════════════════════
        case 'purchase': {
            $ym  = ymWhere('sh.order_date', $year, $month);
            $sql = "
                SELECT sh.id,
                       sh.name,
                       sh.order_date,
                       sh.status,
                       sh.carrier_cost,
                       COALESCE(c.name, '—')      AS supplier,
                       COALESCE(si.items_cost, 0) AS items_cost
                FROM shipments sh
                LEFT JOIN counterparties c ON c.id = sh.counterparty_id
                LEFT JOIN (
                    SELECT shipment_id,
                           SUM(quantity * purchase_price) AS items_cost
                    FROM shipment_items
                    GROUP BY shipment_id
                ) si ON si.shipment_id = sh.id
                WHERE sh.status = 'Завершено'{$ym}
                ORDER BY sh.order_date DESC";

            $rows  = $pdo->query($sql)->fetchAll();
            $total = (float) array_sum(
                array_map(fn($r) => (float)$r['items_cost'] + (float)$r['carrier_cost'], $rows)
            );

            echo json_encode([
                'type'  => 'purchase',
                'total' => $total,
                'rows'  => array_map(fn($r) => [
                    'id'           => (int) $r['id'],
                    'name'         => $r['name'] ?: '—',
                    'date'         => $r['order_date'],
                    'supplier'     => $r['supplier'],
                    'status'       => $r['status'],
                    'items_cost'   => (float) $r['items_cost'],
                    'carrier_cost' => (float) $r['carrier_cost'],
                    'total'        => (float) $r['items_cost'] + (float) $r['carrier_cost'],
                ], $rows),
            ]);
            break;
        }

        // ════════════════════════════════════════════════════════════════════
        // РАСХОДЫ ИП — список с разбивкой по категориям
        // ════════════════════════════════════════════════════════════════════
        case 'biz': {
            $ym  = ymWhere('be.expense_date', $year, $month);
            $sql = "
                SELECT be.id, be.name, be.amount, be.expense_date,
                       COALESCE(ec.name, 'Без категории') AS category,
                       COALESCE(ba.name, '—')             AS account
                FROM business_expenses be
                LEFT JOIN expense_categories ec ON ec.id = be.category_id
                LEFT JOIN bank_accounts ba      ON ba.id = be.account_id
                WHERE 1=1{$ym}
                ORDER BY be.expense_date DESC";

            $rows  = $pdo->query($sql)->fetchAll();
            $total = (float) array_sum(array_column($rows, 'amount'));

            // Сводка по категориям (убывающий порядок)
            $bycat = [];
            foreach ($rows as $r) {
                $bycat[$r['category']] = ($bycat[$r['category']] ?? 0.0) + (float)$r['amount'];
            }
            arsort($bycat);

            echo json_encode([
                'type'  => 'biz',
                'total' => $total,
                'bycat' => $bycat,
                'rows'  => array_map(fn($r) => [
                    'id'       => (int) $r['id'],
                    'name'     => $r['name'],
                    'date'     => $r['expense_date'],
                    'category' => $r['category'],
                    'account'  => $r['account'],
                    'amount'   => (float) $r['amount'],
                ], $rows),
            ]);
            break;
        }

        // ════════════════════════════════════════════════════════════════════
        // НАЛОГ — пошаговая расшифровка формулы
        // FIX: убран мёртвый невалидный SQL ($incSql/$purSql с t(sid,c))
        //      все три слагаемых берутся через единые хелперы
        // ════════════════════════════════════════════════════════════════════
        case 'tax': {
            $income   = (float) $pdo->query(incomeTotalSql($year, $month))->fetchColumn();
            $purchase = (float) $pdo->query(purchaseTotalSql($year, $month))->fetchColumn();
            $biz      = (float) $pdo->query(bizTotalSql($year, $month))->fetchColumn();

            $gross = $income - $purchase - $biz;
            $tax   = $gross > 0 ? round($gross * 0.16) : 0;
            $net   = $gross - $tax;

            echo json_encode([
                'type'     => 'tax',
                'income'   => $income,
                'purchase' => $purchase,
                'biz'      => $biz,
                'gross'    => $gross,
                'tax'      => $tax,
                'net'      => $net,
                'rate'     => 16,
            ]);
            break;
        }

        // ════════════════════════════════════════════════════════════════════
        // МАРЖА / ROI — пошаговая расшифровка формул
        // FIX: используют те же хелперы что tax → гарантированное совпадение цифр
        // ════════════════════════════════════════════════════════════════════
        case 'margin':
        case 'roi': {
            $income   = (float) $pdo->query(incomeTotalSql($year, $month))->fetchColumn();
            $purchase = (float) $pdo->query(purchaseTotalSql($year, $month))->fetchColumn();
            $biz      = (float) $pdo->query(bizTotalSql($year, $month))->fetchColumn();

            $gross  = $income - $purchase - $biz;
            $tax    = $gross > 0 ? round($gross * 0.16) : 0;
            $net    = $gross - $tax;
            $margin = $income   > 0 ? round($net / $income * 100, 2) : 0;
            $roi    = $purchase > 0 ? round(($income - $purchase) / $purchase * 100, 2) : 0;

            echo json_encode([
                'type'     => $type,
                'income'   => $income,
                'purchase' => $purchase,
                'biz'      => $biz,
                'gross'    => $gross,
                'tax'      => $tax,
                'net'      => $net,
                'margin'   => $margin,
                'roi'      => $roi,
            ]);
            break;
        }

        // ════════════════════════════════════════════════════════════════════
        // СКЛАД — остатки с заморозкой
        // ════════════════════════════════════════════════════════════════════
        case 'warehouse': {
            $rows = $pdo->query("
                SELECT w.id, w.name, w.quantity_left, w.quantity_total,
                       w.purchase_price, w.status,
                       COALESCE(c.name, '—') AS supplier
                FROM warehouse w
                LEFT JOIN shipments sh    ON sh.id = w.shipment_id
                LEFT JOIN counterparties c ON c.id = sh.counterparty_id
                WHERE w.quantity_left > 0
                ORDER BY w.name ASC
            ")->fetchAll();

            $total = (float) array_sum(
                array_map(fn($r) => (float)$r['quantity_left'] * (float)$r['purchase_price'], $rows)
            );

            echo json_encode([
                'type'  => 'warehouse',
                'total' => $total,
                'rows'  => array_map(fn($r) => [
                    'id'            => (int)   $r['id'],
                    'name'          =>         $r['name'],
                    'qty_left'      => (int)   $r['quantity_left'],
                    'qty_total'     => (int)   $r['quantity_total'],
                    'purchase_price'=> (float) $r['purchase_price'],
                    'frozen'        => (float) $r['quantity_left'] * (float)$r['purchase_price'],
                    'supplier'      =>         $r['supplier'],
                    'status'        =>         $r['status'],
                ], $rows),
            ]);
            break;
        }

        // ════════════════════════════════════════════════════════════════════
        // В ПУТИ — активные поставки
        // FIX: подзапрос для items_cost → нет GROUP BY на внешнем запросе
        // ════════════════════════════════════════════════════════════════════
        case 'transit': {
            $rows = $pdo->query("
                SELECT sh.id,
                       sh.name,
                       sh.order_date,
                       sh.eta,
                       sh.carrier_cost,
                       COALESCE(c.name, '—')      AS supplier,
                       COALESCE(si.items_cost, 0) AS items_cost
                FROM shipments sh
                LEFT JOIN counterparties c ON c.id = sh.counterparty_id
                LEFT JOIN (
                    SELECT shipment_id,
                           SUM(quantity * purchase_price) AS items_cost
                    FROM shipment_items
                    GROUP BY shipment_id
                ) si ON si.shipment_id = sh.id
                WHERE sh.status = 'В пути'
                ORDER BY sh.eta ASC
            ")->fetchAll();

            $total = (float) array_sum(
                array_map(fn($r) => (float)$r['items_cost'] + (float)$r['carrier_cost'], $rows)
            );

            echo json_encode([
                'type'  => 'transit',
                'total' => $total,
                'rows'  => array_map(fn($r) => [
                    'id'           => (int)   $r['id'],
                    'name'         =>         $r['name'] ?: '—',
                    'supplier'     =>         $r['supplier'],
                    'order_date'   =>         $r['order_date'],
                    'eta'          =>         $r['eta'],
                    'items_cost'   => (float) $r['items_cost'],
                    'carrier_cost' => (float) $r['carrier_cost'],
                    'total'        => (float) $r['items_cost'] + (float)$r['carrier_cost'],
                ], $rows),
            ]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown type: ' . htmlspecialchars($type)]);
    }

} catch (Throwable $e) {
    apiError('Ошибка сервера', $e);
}
