<?php
session_start();
if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
    http_response_code(401);
    exit;
}

require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        text TEXT NOT NULL,
        deadline DATE DEFAULT NULL,
        entity_type VARCHAR(50) DEFAULT NULL,
        entity_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add entity columns if they don't exist yet (migration)
    try {
        $pdo->exec("ALTER TABLE notes ADD COLUMN entity_type VARCHAR(50) DEFAULT NULL");
    } catch (PDOException $e) { /* already exists */ }
    try {
        $pdo->exec("ALTER TABLE notes ADD COLUMN entity_id INT DEFAULT NULL");
    } catch (PDOException $e) { /* already exists */ }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        if (isset($_GET['entity_type']) && isset($_GET['entity_id'])) {
            // Notes attached to a specific entity
            $stmt = $pdo->prepare("SELECT * FROM notes WHERE entity_type = ? AND entity_id = ? ORDER BY (deadline IS NULL) ASC, deadline ASC, created_at DESC");
            $stmt->execute([$_GET['entity_type'], (int)$_GET['entity_id']]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } elseif (isset($_GET['standalone'])) {
            // Dashboard: only standalone notes (no entity attached)
            $stmt = $pdo->query("SELECT * FROM notes WHERE entity_type IS NULL ORDER BY (deadline IS NULL) ASC, deadline ASC, created_at DESC");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        } else {
            // All notes
            $stmt = $pdo->query("SELECT * FROM notes ORDER BY (deadline IS NULL) ASC, deadline ASC, created_at DESC");
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        }

    } elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $text       = trim($data['text'] ?? '');
        $deadline   = !empty($data['deadline']) ? $data['deadline'] : null;
        $entityType = !empty($data['entity_type']) ? $data['entity_type'] : null;
        $entityId   = isset($data['entity_id']) && $data['entity_id'] !== null ? (int)$data['entity_id'] : null;
        if ($text === '') {
            http_response_code(400);
            echo json_encode(['error' => 'text is required']);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO notes (text, deadline, entity_type, entity_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$text, $deadline, $entityType, $entityId]);
        echo json_encode(['id' => $pdo->lastInsertId(), 'ok' => true]);

    } elseif ($method === 'PUT') {
        $data     = json_decode(file_get_contents('php://input'), true);
        $id       = (int)($data['id'] ?? 0);
        $text     = trim($data['text'] ?? '');
        $deadline = !empty($data['deadline']) ? $data['deadline'] : null;
        if (!$id || $text === '') {
            http_response_code(400);
            echo json_encode(['error' => 'id and text required']);
            exit;
        }
        $stmt = $pdo->prepare("UPDATE notes SET text=?, deadline=? WHERE id=?");
        $stmt->execute([$text, $deadline, $id]);
        echo json_encode(['ok' => true]);

    } elseif ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'id required']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM notes WHERE id=?");
        $stmt->execute([$id]);
        echo json_encode(['ok' => true]);

    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
