<?php
session_start();
if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
    http_response_code(401);
    exit;
}

require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS reminders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entity_type VARCHAR(50) NOT NULL,
        entity_id INT NOT NULL,
        text TEXT NOT NULL,
        remind_at DATETIME NOT NULL,
        is_done TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        if (!empty($_GET['entity_type']) && !empty($_GET['entity_id'])) {
            $stmt = $pdo->prepare("SELECT * FROM reminders WHERE entity_type=? AND entity_id=? ORDER BY remind_at ASC");
            $stmt->execute([$_GET['entity_type'], (int)$_GET['entity_id']]);
        } else {
            $stmt = $pdo->query("SELECT * FROM reminders WHERE is_done=0 ORDER BY remind_at ASC");
        }
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

    } elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        $entity_type = trim($data['entity_type'] ?? '');
        $entity_id   = (int)($data['entity_id'] ?? 0);
        $text        = trim($data['text'] ?? '');
        $remind_at   = $data['remind_at'] ?? '';
        if (!$entity_type || !$entity_id || $text === '' || !$remind_at) {
            http_response_code(400);
            echo json_encode(['error' => 'entity_type, entity_id, text, remind_at are required']);
            exit;
        }
        $stmt = $pdo->prepare("INSERT INTO reminders (entity_type, entity_id, text, remind_at) VALUES (?,?,?,?)");
        $stmt->execute([$entity_type, $entity_id, $text, $remind_at]);
        echo json_encode(['id' => $pdo->lastInsertId(), 'ok' => true]);

    } elseif ($method === 'PUT') {
        $id   = (int)($_GET['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'id required']);
            exit;
        }
        $data = json_decode(file_get_contents('php://input'), true);

        // Toggle is_done or full update
        if (isset($data['is_done']) && !isset($data['text']) && !isset($data['remind_at'])) {
            $stmt = $pdo->prepare("UPDATE reminders SET is_done=? WHERE id=?");
            $stmt->execute([(int)$data['is_done'], $id]);
        } else {
            $text      = trim($data['text'] ?? '');
            $remind_at = $data['remind_at'] ?? '';
            $is_done   = isset($data['is_done']) ? (int)$data['is_done'] : null;
            if ($text === '' || !$remind_at) {
                http_response_code(400);
                echo json_encode(['error' => 'text and remind_at required']);
                exit;
            }
            if ($is_done !== null) {
                $stmt = $pdo->prepare("UPDATE reminders SET text=?, remind_at=?, is_done=? WHERE id=?");
                $stmt->execute([$text, $remind_at, $is_done, $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE reminders SET text=?, remind_at=? WHERE id=?");
                $stmt->execute([$text, $remind_at, $id]);
            }
        }
        echo json_encode(['ok' => true]);

    } elseif ($method === 'DELETE') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'id required']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM reminders WHERE id=?");
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
