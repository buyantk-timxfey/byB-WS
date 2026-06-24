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

    // Получить список или одного контрагента
    case 'GET':
        if (isset($_GET['id'])) {
            // Карточка контрагента
            $stmt = $pdo->prepare("SELECT * FROM counterparties WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            $cp = $stmt->fetch();

            // История поставок
            $stmt2 = $pdo->prepare("
                SELECT s.id, s.order_date, s.status,
                    COALESCE(SUM(si.quantity * si.purchase_price), 0) + s.carrier_cost as total
                FROM shipments s
                LEFT JOIN shipment_items si ON si.shipment_id = s.id
                WHERE s.counterparty_id = ?
                GROUP BY s.id
                ORDER BY s.order_date DESC
            ");
            $stmt2->execute([$_GET['id']]);
            $cp['shipments'] = $stmt2->fetchAll();

            echo json_encode($cp);

        } elseif (isset($_GET['type'])) {
            // Фильтр по типу
            $type = $_GET['type'];
            if ($type === 'supplier') {
                $stmt = $pdo->prepare("SELECT * FROM counterparties WHERE type IN ('Поставщик', 'Оба') ORDER BY name");
            } elseif ($type === 'buyer') {
                $stmt = $pdo->prepare("SELECT * FROM counterparties WHERE type IN ('Покупатель', 'Оба') ORDER BY name");
            } else {
                $stmt = $pdo->prepare("SELECT * FROM counterparties ORDER BY name");
            }
            $stmt->execute();
            echo json_encode($stmt->fetchAll());

        } else {
            // Все контрагенты
            $stmt = $pdo->query("SELECT * FROM counterparties ORDER BY name");
            echo json_encode($stmt->fetchAll());
        }
        break;

    // Добавить контрагента
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("
            INSERT INTO counterparties (company_type, name, type, requisites, comment)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['company_type'],
            $data['name'],
            $data['type'],
            $data['requisites'] ?? '',
            $data['comment'] ?? ''
        ]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        break;

        // Обновить контрагента
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("
            UPDATE counterparties
            SET company_type = ?, name = ?, type = ?, requisites = ?, comment = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $data['company_type'],
            $data['name'],
            $data['type'],
            $data['requisites'] ?? '',
            $data['comment'] ?? '',
            $data['id']
        ]);
        echo json_encode(['success' => true]);
        break;

    // Удалить контрагента
    case 'DELETE':
        $stmt = $pdo->prepare("DELETE FROM counterparties WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        echo json_encode(['success' => true]);
        break;
}
} catch (PDOException $e) {
    echo json_encode(["error" => $e->getMessage()]);
}