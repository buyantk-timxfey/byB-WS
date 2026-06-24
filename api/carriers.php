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

    // Получить список или одну ТК
    case 'GET':
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM carriers WHERE id = ?");
            $stmt->execute([$_GET['id']]);
            echo json_encode($stmt->fetch());
        } else {
            $stmt = $pdo->query("SELECT * FROM carriers ORDER BY name");
            echo json_encode($stmt->fetchAll());
        }
        break;

    // Добавить ТК
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (empty($data['name'])) {
            echo json_encode(['error' => 'Название обязательно']);
            break;
        }
        $stmt = $pdo->prepare("
            INSERT INTO carriers (name, website, comment)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([
            $data['name'],
            $data['website'] ?? '',
            $data['comment'] ?? ''
        ]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        break;

// Обновить ТК
    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        $stmt = $pdo->prepare("
            UPDATE carriers SET name = ?, website = ?, comment = ? WHERE id = ?
        ");
        $stmt->execute([
            $data['name'],
            $data['website'] ?? '',
            $data['comment'] ?? '',
            $data['id']
        ]);
        echo json_encode(['success' => true]);
        break;

    // Удалить ТК
    case 'DELETE':
        $stmt = $pdo->prepare("DELETE FROM carriers WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        echo json_encode(['success' => true]);
        break;
}
} catch (PDOException $e) {
    echo json_encode(["error" => $e->getMessage()]);
}