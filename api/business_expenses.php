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

try {
switch ($method) {

    case 'GET':
        // Категории расходов
        if (isset($_GET['categories'])) {
            echo json_encode($pdo->query("SELECT * FROM expense_categories ORDER BY name")->fetchAll());
            break;
        }

        // Список расходов с фильтром
        $where = ['1=1'];
        $params = [];

        if (!empty($_GET['month']) && !empty($_GET['year'])) {
            $where[] = 'MONTH(be.expense_date) = ? AND YEAR(be.expense_date) = ?';
            $params[] = $_GET['month'];
            $params[] = $_GET['year'];
        }
        if (!empty($_GET['category_id'])) {
            $where[] = 'be.category_id = ?';
            $params[] = $_GET['category_id'];
        }

        $whereStr = implode(' AND ', $where);
        $stmt = $pdo->prepare("
            SELECT be.*, ec.name as category_name, ba.name as account_name
            FROM business_expenses be
            LEFT JOIN expense_categories ec ON ec.id = be.category_id
            LEFT JOIN bank_accounts ba ON ba.id = be.account_id
            WHERE $whereStr
            ORDER BY be.expense_date DESC
        ");
        $stmt->execute($params);
        echo json_encode($stmt->fetchAll());
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);

        // Управление категориями
        if (isset($data['action']) && $data['action'] === 'add_category') {
            if (empty($data['name'])) { echo json_encode(['error' => 'Укажите название']); break; }
            $pdo->prepare("INSERT INTO expense_categories (name) VALUES (?)")->execute([$data['name']]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;
        }

        // Создать расход
        if (empty($data['name'])) { echo json_encode(['error' => 'Укажите название']); break; }
        if (empty($data['amount']) || $data['amount'] <= 0) { echo json_encode(['error' => 'Укажите сумму']); break; }
        if (empty($data['expense_date'])) { echo json_encode(['error' => 'Укажите дату']); break; }

        $pdo->prepare("
            INSERT INTO business_expenses (category_id, name, amount, expense_date, account_id, comment)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([
            $data['category_id'] ?? null,
            $data['name'],
            $data['amount'],
            $data['expense_date'],
            $data['account_id'] ?? null,
            $data['comment'] ?? null
        ]);
        $expenseId = $pdo->lastInsertId();

        // Банковская операция — списание
        if (!empty($data['account_id'])) {
    $pdo->prepare("
        INSERT INTO bank_operations (account_id, type, amount, description, operation_date, expense_id)
        VALUES (?, 'Расход', ?, ?, ?, ?)
    ")->execute([
        $data['account_id'],
        $data['amount'],
        $data['name'],
        $data['expense_date'],
        $expenseId
    ]);
}

        echo json_encode(['success' => true, 'id' => $expenseId]);
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);

        // Получаем старый account_id для удаления банковской операции
        $old = $pdo->prepare("SELECT * FROM business_expenses WHERE id = ?");
        $old->execute([$data['id']]);
        $oldExpense = $old->fetch();

        $pdo->prepare("
            UPDATE business_expenses
            SET category_id = ?, name = ?, amount = ?, expense_date = ?, account_id = ?, comment = ?
            WHERE id = ?
        ")->execute([
            $data['category_id'] ?? null,
            $data['name'],
            $data['amount'],
            $data['expense_date'],
            $data['account_id'] ?? null,
            $data['comment'] ?? null,
            $data['id']
        ]);

        // Пересоздаём банковскую операцию если был счёт
        // Удаляем старую (по описанию и дате — нет прямой привязки)
        // Простое решение: пересоздаём
        $pdo->prepare("DELETE FROM bank_operations WHERE expense_id = ?")->execute([$data['id']]);
if (!empty($data['account_id'])) {
    $pdo->prepare("
        INSERT INTO bank_operations (account_id, type, amount, description, operation_date, expense_id)
        VALUES (?, 'Расход', ?, ?, ?, ?)
    ")->execute([$data['account_id'], $data['amount'], $data['name'], $data['expense_date'], $data['id']]);
}

        echo json_encode(['success' => true]);
        break;

    case 'DELETE':
        if (isset($_GET['category'])) {
            $pdo->prepare("DELETE FROM expense_categories WHERE id = ?")->execute([$_GET['category']]);
            echo json_encode(['success' => true]);
            break;
        }

        $old = $pdo->prepare("SELECT * FROM business_expenses WHERE id = ?");
        $old->execute([$_GET['id']]);
        $oldExpense = $old->fetch();

        if ($oldExpense) {
    $pdo->prepare("DELETE FROM bank_operations WHERE expense_id = ?")->execute([$_GET['id']]);
}

        $pdo->prepare("DELETE FROM business_expenses WHERE id = ?")->execute([$_GET['id']]);
        echo json_encode(['success' => true]);
        break;
}
} catch (PDOException $e) {
    echo json_encode(["error" => $e->getMessage()]);
}