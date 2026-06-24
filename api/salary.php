<?php
require_once '../config.php';
session_start();
if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
    http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit;
}
header('Content-Type: application/json');

// Создаём таблицу если не существует
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS salary_entries (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        period      VARCHAR(100) NOT NULL,
        income      DECIMAL(15,2) NOT NULL DEFAULT 0,
        expenses    DECIMAL(15,2) NOT NULL DEFAULT 0,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {}

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {

        case 'GET':
            $rows = $pdo->query("SELECT * FROM salary_entries ORDER BY id DESC")->fetchAll();
            foreach ($rows as &$row) {
                $base = (float)$row['income'] - (float)$row['expenses'];
                $row['base']   = round($base, 2);
                $row['tax']    = round($base * 0.16, 2);
                $row['net']    = round($base * 0.84, 2);
                $row['salary'] = round($base * 0.84 * 0.20, 2);
            }
            echo json_encode($rows);
            break;

        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['period'])) { echo json_encode(['error' => 'Укажите период']); break; }
            $stmt = $pdo->prepare("INSERT INTO salary_entries (period, income, expenses) VALUES (?, ?, ?)");
            $stmt->execute([$data['period'], (float)($data['income'] ?? 0), (float)($data['expenses'] ?? 0)]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;

        case 'PUT':
            $data = json_decode(file_get_contents('php://input'), true);
            if (empty($data['period'])) { echo json_encode(['error' => 'Укажите период']); break; }
            $stmt = $pdo->prepare("UPDATE salary_entries SET period=?, income=?, expenses=? WHERE id=?");
            $stmt->execute([$data['period'], (float)($data['income'] ?? 0), (float)($data['expenses'] ?? 0), (int)$data['id']]);
            echo json_encode(['success' => true]);
            break;

        case 'DELETE':
            $stmt = $pdo->prepare("DELETE FROM salary_entries WHERE id=?");
            $stmt->execute([(int)$_GET['id']]);
            echo json_encode(['success' => true]);
            break;
    }
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
