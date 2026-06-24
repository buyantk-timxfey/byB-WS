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

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("
                SELECT p.*, wc.name as category_name,
                    COALESCE(SUM(w.quantity_left), 0) as stock_left,
                    COALESCE(SUM(w.quantity_total), 0) as stock_total,
                    COUNT(DISTINCT w.shipment_id) as shipments_count
                FROM products p
                LEFT JOIN warehouse_categories wc ON wc.id = p.category_id
                LEFT JOIN warehouse w ON w.product_id = p.id
                WHERE p.id = ?
                GROUP BY p.id
            ");
            $stmt->execute([$_GET['id']]);
            $product = $stmt->fetch();

            // История поставок
            $sh = $pdo->prepare("
                SELECT w.id as warehouse_id, w.quantity_total, w.quantity_left, w.purchase_price, w.status,
                    s.id as shipment_id, s.order_date,
                    COALESCE(c.company_type,'') as supplier_type,
                    COALESCE(c.name,'—') as supplier_name
                FROM warehouse w
                JOIN shipments s ON s.id = w.shipment_id
                LEFT JOIN counterparties c ON c.id = s.counterparty_id
                WHERE w.product_id = ?
                ORDER BY s.order_date DESC
            ");
            $sh->execute([$_GET['id']]);
            $product['shipments'] = $sh->fetchAll();

            // История продаж
            $sl = $pdo->prepare("
                SELECT si.quantity, s.sale_date, s.sale_price, s.status as sale_status,
                    COALESCE(c.name, s.buyer_name, '—') as buyer
                FROM sale_items si
                JOIN sales s ON s.id = si.sale_id
                JOIN warehouse w ON w.id = si.warehouse_id
                LEFT JOIN counterparties c ON c.id = s.counterparty_id
                WHERE w.product_id = ?
                ORDER BY s.sale_date DESC
            ");
            $sl->execute([$_GET['id']]);
            $product['sales'] = $sl->fetchAll();

            echo json_encode($product);
            break;
        }

        // Поиск по части слова
        $search = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : '%';
        $catFilter = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;

        $where = "WHERE p.name LIKE ?";
        $params = [$search];
        if ($catFilter) { $where .= " AND p.category_id = ?"; $params[] = $catFilter; }

        $stmt = $pdo->prepare("
            SELECT p.*, wc.name as category_name,
                COALESCE(SUM(w.quantity_left), 0) as stock_left,
                COALESCE(SUM(w.quantity_total), 0) as stock_total,
                COUNT(DISTINCT CASE WHEN w.quantity_left > 0 THEN w.id END) as batches_available
            FROM products p
            LEFT JOIN warehouse_categories wc ON wc.id = p.category_id
            LEFT JOIN warehouse w ON w.product_id = p.id
            $where
            GROUP BY p.id
            ORDER BY p.name
        ");
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['name'])) { echo json_encode(['error' => 'Укажите название']); break; }

        $pdo->prepare("INSERT INTO products (name, category_id, unit, comment, sale_price) VALUES (?, ?, ?, ?, ?)")
            ->execute([$data['name'], $data['category_id'] ?? null, $data['unit'] ?? 'шт', $data['comment'] ?? null, isset($data['sale_price']) ? $data['sale_price'] : null]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        $pdo->prepare("UPDATE products SET name=?, category_id=?, unit=?, comment=?, sale_price=? WHERE id=?")
            ->execute([$data['name'], $data['category_id'] ?? null, $data['unit'] ?? 'шт', $data['comment'] ?? null, isset($data['sale_price']) ? $data['sale_price'] : null, $data['id']]);
        echo json_encode(['success' => true]);
        break;

    case 'DELETE':
        $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$_GET['id']]);
        echo json_encode(['success' => true]);
        break;
}
