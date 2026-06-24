<?php
require_once '../config.php';
session_start();
if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$like    = '%' . $q . '%';
$results = [];

// Поставки
try {
    $stmt = $pdo->prepare("
        SELECT s.id, COALESCE(s.name,'—') as name, s.status,
               COALESCE(c.company_type,'') as company_type,
               COALESCE(c.name,'') as counterparty
        FROM shipments s
        LEFT JOIN counterparties c ON c.id = s.counterparty_id
        WHERE s.name LIKE ? OR c.name LIKE ? OR s.tracking LIKE ?
        ORDER BY s.order_date DESC LIMIT 5
    ");
    $stmt->execute([$like, $like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = [
            'type'     => 'shipment',
            'id'       => (int)$r['id'],
            'title'    => $r['name'],
            'subtitle' => trim($r['company_type'] . ' ' . $r['counterparty']),
            'meta'     => $r['status'],
            'page'     => 'shipments',
        ];
    }
} catch (Throwable $e) { error_log('search shipments: ' . $e->getMessage()); }

// Продажи
try {
    $stmt = $pdo->prepare("
        SELECT s.id, w.name as item_name,
               COALESCE(s.buyer_name,'') as buyer_name,
               s.sale_price, s.sale_date,
               COALESCE(c.company_type,'') as company_type,
               COALESCE(c.name,'') as counterparty
        FROM sales s
        LEFT JOIN warehouse w ON w.id = s.warehouse_id
        LEFT JOIN counterparties c ON c.id = s.counterparty_id
        WHERE w.name LIKE ? OR s.buyer_name LIKE ? OR c.name LIKE ?
        ORDER BY s.sale_date DESC LIMIT 5
    ");
    $stmt->execute([$like, $like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $buyer = $r['buyer_name'] ?: trim($r['company_type'] . ' ' . $r['counterparty']);
        $results[] = [
            'type'     => 'sale',
            'id'       => (int)$r['id'],
            'title'    => $r['item_name'] ?? '—',
            'subtitle' => $buyer,
            'meta'     => number_format((float)$r['sale_price'], 0, '.', ' ') . ' ₽',
            'page'     => 'sales',
        ];
    }
} catch (Throwable $e) { error_log('search sales: ' . $e->getMessage()); }

// Склад
try {
    $stmt = $pdo->prepare("
        SELECT id, name, quantity_left, status
        FROM warehouse
        WHERE name LIKE ?
        ORDER BY name LIMIT 5
    ");
    $stmt->execute([$like]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = [
            'type'     => 'warehouse',
            'id'       => (int)$r['id'],
            'title'    => $r['name'],
            'subtitle' => 'Остаток: ' . $r['quantity_left'] . ' шт.',
            'meta'     => $r['status'],
            'page'     => 'warehouse',
        ];
    }
} catch (Throwable $e) { error_log('search warehouse: ' . $e->getMessage()); }

// Контрагенты
try {
    $stmt = $pdo->prepare("
        SELECT id, company_type, name, type
        FROM counterparties
        WHERE name LIKE ?
        ORDER BY name LIMIT 5
    ");
    $stmt->execute([$like]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = [
            'type'     => 'counterparty',
            'id'       => (int)$r['id'],
            'title'    => trim($r['company_type'] . ' ' . $r['name']),
            'subtitle' => $r['type'],
            'meta'     => '',
            'page'     => 'counterparties',
        ];
    }
} catch (Throwable $e) { error_log('search counterparties: ' . $e->getMessage()); }

// Перевозчики
try {
    $stmt = $pdo->prepare("
        SELECT id, name, website
        FROM carriers
        WHERE name LIKE ?
        ORDER BY name LIMIT 5
    ");
    $stmt->execute([$like]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = [
            'type'     => 'carrier',
            'id'       => (int)$r['id'],
            'title'    => $r['name'],
            'subtitle' => $r['website'] ?: '',
            'meta'     => '',
            'page'     => 'carriers',
        ];
    }
} catch (Throwable $e) { error_log('search carriers: ' . $e->getMessage()); }

// Расходы предпринимателя
try {
    $stmt = $pdo->prepare("
        SELECT be.id, COALESCE(ec.name, '') as category_name,
               be.amount, be.expense_date
        FROM business_expenses be
        LEFT JOIN expense_categories ec ON ec.id = be.category_id
        WHERE ec.name LIKE ? OR CAST(be.amount AS CHAR) LIKE ?
        ORDER BY be.expense_date DESC LIMIT 5
    ");
    $stmt->execute([$like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = [
            'type'     => 'expense',
            'id'       => (int)$r['id'],
            'title'    => $r['category_name'] ?: '—',
            'subtitle' => $r['expense_date'] ? date('d.m.Y', strtotime($r['expense_date'])) : '',
            'meta'     => number_format((float)$r['amount'], 0, '.', ' ') . ' ₽',
            'page'     => 'finances',
        ];
    }
} catch (Throwable $e) { error_log('search expenses: ' . $e->getMessage()); }

// Банковские операции
try {
    $stmt = $pdo->prepare("
        SELECT bo.id, bo.type, bo.amount, bo.operation_date,
               COALESCE(bo.description,'') as description,
               COALESCE(ba.name,'') as account_name
        FROM bank_operations bo
        LEFT JOIN bank_accounts ba ON ba.id = bo.account_id
        WHERE bo.description LIKE ? OR ba.name LIKE ? OR bo.type LIKE ?
        ORDER BY bo.operation_date DESC LIMIT 5
    ");
    $stmt->execute([$like, $like, $like]);
    foreach ($stmt->fetchAll() as $r) {
        $results[] = [
            'type'     => 'bank',
            'id'       => (int)$r['id'],
            'title'    => $r['type'] . ' · ' . $r['account_name'],
            'subtitle' => $r['description'],
            'meta'     => number_format((float)$r['amount'], 0, '.', ' ') . ' ₽',
            'page'     => 'bank',
        ];
    }
} catch (Throwable $e) { error_log('search bank: ' . $e->getMessage()); }

echo json_encode($results, JSON_UNESCAPED_UNICODE);
