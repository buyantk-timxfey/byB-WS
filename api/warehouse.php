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
        if (isset($_GET['categories'])) {
            echo json_encode($pdo->query("SELECT * FROM warehouse_categories ORDER BY id")->fetchAll());
            break;
        }

        if (isset($_GET['chain'])) {
            $warehouseId = (int)$_GET['chain'];
            $wStmt = $pdo->prepare("
                SELECT w.*, p.name as product_name, wc.name as category_name,
                    s.order_date as shipment_order_date, s.eta as shipment_eta,
                    s.status as shipment_status, s.carrier_cost,
                    COALESCE(c.company_type,'') as supplier_type,
                    COALESCE(c.name,'—') as supplier_name,
                    cr.name as carrier_name, s.tracking
                FROM warehouse w
                LEFT JOIN products p ON p.id = w.product_id
                LEFT JOIN warehouse_categories wc ON wc.id = w.category_id
                LEFT JOIN shipments s ON s.id = w.shipment_id
                LEFT JOIN counterparties c ON c.id = s.counterparty_id
                LEFT JOIN carriers cr ON cr.id = s.carrier_id
                WHERE w.id = ?
            ");
            $wStmt->execute([$warehouseId]);
            $item = $wStmt->fetch();
            if ($item && !$item['name']) $item['name'] = $item['product_name'];

            $salesStmt = $pdo->prepare("
                SELECT si.quantity, si.sale_id, si.sale_price as item_sale_price,
                    s.sale_date, s.sale_price, s.status as sale_status,
                    COALESCE(c.company_type,'') as buyer_type,
                    COALESCE(c.name, s.buyer_name,'—') as buyer_name
                FROM sale_items si
                JOIN sales s ON s.id = si.sale_id
                LEFT JOIN counterparties c ON c.id = s.counterparty_id
                WHERE si.warehouse_id = ?
                ORDER BY s.sale_date ASC
            ");
            $salesStmt->execute([$warehouseId]);
            $sales = $salesStmt->fetchAll();

            // Цена продажи за штуку × количество, любой статус кроме Отменено
            $totalSold = 0;
            $totalRevenue = 0;
            foreach ($sales as &$sale) {
                if ($sale['sale_status'] !== 'Отменено') {
                    $totalSold += $sale['quantity'];
                    $unitPrice = floatval($sale['item_sale_price']) > 0
                        ? floatval($sale['item_sale_price'])
                        : floatval($sale['sale_price']);
                    $totalRevenue += $unitPrice * $sale['quantity'];
                    $sale['sale_price'] = $unitPrice * $sale['quantity'];
                }
            }
            unset($sale);
            $purchaseCost = $item['purchase_price'] * $item['quantity_total'];

            echo json_encode([
                'warehouse' => $item, 'sales' => $sales,
                'stats' => ['total_sold'=>$totalSold,'total_left'=>$item['quantity_left'],
                    'total_revenue'=>$totalRevenue,'purchase_cost'=>$purchaseCost,
                    'profit'=>$totalRevenue-$purchaseCost]
            ]);
            break;
        }

        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("
                SELECT w.*, p.name as product_name, wc.name as category_name
                FROM warehouse w
                LEFT JOIN products p ON p.id = w.product_id
                LEFT JOIN warehouse_categories wc ON wc.id = w.category_id
                WHERE w.id = ?
            ");
            $stmt->execute([$_GET['id']]);
            $item = $stmt->fetch();
            if ($item && !$item['name']) $item['name'] = $item['product_name'];

            $stmt2 = $pdo->prepare("
                SELECT s.sale_date, s.sale_price, s.status, si.quantity,
                    COALESCE(c.name, s.buyer_name,'—') as counterparty
                FROM sale_items si
                JOIN sales s ON s.id = si.sale_id
                LEFT JOIN counterparties c ON c.id = s.counterparty_id
                WHERE si.warehouse_id = ?
                ORDER BY s.sale_date DESC
            ");
            $stmt2->execute([$_GET['id']]);
            $item['sales'] = $stmt2->fetchAll();
            echo json_encode($item);
            break;
        }

        if (isset($_GET['status']) && $_GET['status'] === 'available') {
            $stmt = $pdo->query("
                SELECT w.*, COALESCE(p.name, w.name) as display_name,
                    wc.name as category_name
                FROM warehouse w
                LEFT JOIN products p ON p.id = w.product_id
                LEFT JOIN warehouse_categories wc ON wc.id = w.category_id
                WHERE w.quantity_left > 0 AND w.status != 'Продан'
                ORDER BY display_name
            ");
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) { if (!$r['name']) $r['name'] = $r['display_name']; }
            unset($r);
            echo json_encode($rows);
            break;
        }

        $stmt = $pdo->query("
            SELECT w.*, COALESCE(p.name, w.name) as display_name,
                wc.name as category_name, wc.color as category_color,
                s.order_date as shipment_date
            FROM warehouse w
            LEFT JOIN products p ON p.id = w.product_id
            LEFT JOIN warehouse_categories wc ON wc.id = w.category_id
            LEFT JOIN shipments s ON s.id = w.shipment_id
            ORDER BY display_name
        ");
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) { if (!$r['name']) $r['name'] = $r['display_name']; }
            unset($r);
        echo json_encode($rows);
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($data['action']) && $data['action'] === 'add_category') {
            if (empty($data['name'])) { echo json_encode(['error' => 'Укажите название']); break; }
            $pdo->prepare("INSERT INTO warehouse_categories (name) VALUES (?)")->execute([$data['name']]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;
        }
        if (isset($data['action']) && $data['action'] === 'set_category') {
            $pdo->prepare("UPDATE warehouse SET category_id=? WHERE id=?")->execute([$data['category_id'], $data['warehouse_id']]);
            echo json_encode(['success' => true]);
            break;
        }
        echo json_encode(['error' => 'Unknown action']);
        break;

    case 'DELETE':
        if (isset($_GET['category'])) {
            $pdo->prepare("DELETE FROM warehouse_categories WHERE id=? AND id>1")->execute([$_GET['category']]);
            echo json_encode(['success' => true]);
            break;
        }
        $pdo->prepare("DELETE FROM warehouse WHERE id=?")->execute([$_GET['id']]);
        echo json_encode(['success' => true]);
        break;
}
} catch (PDOException $e) {
    echo json_encode(["error" => $e->getMessage()]);
}