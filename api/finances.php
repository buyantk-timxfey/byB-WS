<?php
require_once '../config.php';
session_start();
if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
    http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit;
}
header('Content-Type: application/json');

try {

// Фильтр по месяцу/году (опционально)
$whereMonth = '';
$params = [];

if (!empty($_GET['month']) && !empty($_GET['year'])) {
    $whereMonth = "AND MONTH(sale_date) = ? AND YEAR(sale_date) = ?";
    $params = [$_GET['month'], $_GET['year']];
}

// Общий доход — только ОПЛАЧЕННЫЕ продажи (FIX: был учёт всех статусов)
$incomeStmt = $pdo->prepare("
    SELECT COALESCE(SUM(sale_price), 0)
    FROM sales
    WHERE status = 'Оплачено' $whereMonth
");
$incomeStmt->execute($params);
$totalIncome = $incomeStmt->fetchColumn();



$expenseWhereMonth = '';
$expenseParams = [];
if (!empty($_GET['month']) && !empty($_GET['year'])) {
    $lastDay = date('Y-m-t', mktime(0,0,0,$_GET['month'],1,$_GET['year']));
    $expenseWhereMonth = "AND s.order_date <= ?";
    $expenseParams = [$lastDay];
}

$expenseStmt = $pdo->prepare("
    SELECT
        COALESCE((SELECT SUM(si.quantity * si.purchase_price)
                  FROM shipment_items si JOIN shipments s ON s.id = si.shipment_id
                  WHERE s.status != '⚠️' $expenseWhereMonth), 0)
        + COALESCE((SELECT SUM(carrier_cost) FROM shipments
                    WHERE status != '⚠️' $expenseWhereMonth), 0)
");
$expenseStmt->execute(array_merge($expenseParams, $expenseParams));
$totalExpense = $expenseStmt->fetchColumn();

$bizExpParams = [];
$bizExpWhere = '';
if (!empty($_GET['month']) && !empty($_GET['year'])) {
    $lastDay = date('Y-m-t', mktime(0,0,0,$_GET['month'],1,$_GET['year']));
    $bizExpWhere = 'WHERE expense_date <= ?';
    $bizExpParams = [$lastDay];
}
$bizExpStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM business_expenses $bizExpWhere");
$bizExpStmt->execute($bizExpParams);
$totalBizExp = $bizExpStmt->fetchColumn();

$totalProfit = $totalIncome - $totalExpense - $totalBizExp;
$margin = $totalIncome > 0 ? round(($totalProfit / $totalIncome) * 100, 1) : 0;

// Лента операций (поставки + продажи вместе)
$operationsStmt = $pdo->prepare("
    SELECT
        'shipment' as op_type,
        s.id,
        s.order_date as op_date,
        COALESCE(c.name, '—') as counterparty,
        COALESCE(SUM(si.quantity * si.purchase_price), 0) + s.carrier_cost as amount,
        s.status
    FROM shipments s
    LEFT JOIN counterparties c ON c.id = s.counterparty_id
    LEFT JOIN shipment_items si ON si.shipment_id = s.id
    GROUP BY s.id

    UNION ALL

    SELECT
        'sale' as op_type,
        sa.id,
        sa.sale_date as op_date,
        COALESCE(c.name, sa.buyer_name, '—') as counterparty,
        sa.sale_price as amount,
        sa.status
    FROM sales sa
    LEFT JOIN counterparties c ON c.id = sa.counterparty_id

    ORDER BY op_date DESC
");
$operationsStmt->execute();
$operations = $operationsStmt->fetchAll();

echo json_encode([
    'total_income'  => $totalIncome,
    'total_expense' => $totalExpense,
    'total_profit'  => $totalProfit,
    'margin'        => $margin,
    'operations'    => $operations
]);

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
