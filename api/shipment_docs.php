<?php
require_once '../config.php';
session_start();
if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
    http_response_code(401); echo json_encode(['error' => 'Unauthorized']); exit;
}

// Автосоздание таблицы
$pdo->exec("CREATE TABLE IF NOT EXISTS shipment_docs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    shipment_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_shipment_id (shipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$uploadDir = __DIR__ . '/../uploads/shipment_docs/';
$method = $_SERVER['REQUEST_METHOD'];

// ── Отдача файла (прямой доступ к uploads/ закрыт .htaccess) ────────────────
if ($method === 'GET' && isset($_GET['download'])) {
    $stmt = $pdo->prepare("SELECT filename, original_name FROM shipment_docs WHERE id = ?");
    $stmt->execute([(int)$_GET['download']]);
    $doc = $stmt->fetch();
    $path = $doc ? $uploadDir . basename($doc['filename']) : '';
    if (!$doc || !is_file($path)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Документ не найден']);
        exit;
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . rawurlencode($doc['original_name']) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

header('Content-Type: application/json');

try {
    switch ($method) {
        case 'GET':
            $stmt = $pdo->prepare("SELECT id, shipment_id, filename, original_name, uploaded_at FROM shipment_docs WHERE shipment_id = ? ORDER BY uploaded_at DESC");
            $stmt->execute([$_GET['id']]);
            echo json_encode($stmt->fetchAll());
            break;

        case 'POST':
            $shipmentId = intval($_POST['shipment_id'] ?? 0);
            if (!$shipmentId) { http_response_code(400); echo json_encode(['error' => 'shipment_id required']); exit; }
            if (empty($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400); echo json_encode(['error' => 'Файл не загружен']); exit;
            }
            $file = $_FILES['pdf'];
            if ($file['type'] !== 'application/pdf' && !str_ends_with(strtolower($file['name']), '.pdf')) {
                http_response_code(400); echo json_encode(['error' => 'Только PDF файлы']); exit;
            }
            // Проверяем содержимое, а не только расширение: PDF начинается с "%PDF-"
            $head = file_get_contents($file['tmp_name'], false, null, 0, 5);
            if ($head !== '%PDF-') {
                http_response_code(400); echo json_encode(['error' => 'Файл не является PDF']); exit;
            }
            if ($file['size'] > 20 * 1024 * 1024) {
                http_response_code(400); echo json_encode(['error' => 'Файл превышает 20 МБ']); exit;
            }
            $ext = 'pdf';
            $filename = 'doc_' . $shipmentId . '_' . uniqid() . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) {
                http_response_code(500); echo json_encode(['error' => 'Ошибка сохранения файла']); exit;
            }
            $originalName = preg_replace('/[^\w\s\.\-\(\)]/u', '_', $file['name']);
            $pdo->prepare("INSERT INTO shipment_docs (shipment_id, filename, original_name) VALUES (?, ?, ?)")
                ->execute([$shipmentId, $filename, $originalName]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'filename' => $filename, 'original_name' => $originalName]);
            break;

        case 'DELETE':
            $id = intval($_GET['id'] ?? 0);
            $stmt = $pdo->prepare("SELECT filename FROM shipment_docs WHERE id = ?");
            $stmt->execute([$id]);
            $doc = $stmt->fetch();
            if ($doc) {
                @unlink($uploadDir . $doc['filename']);
                $pdo->prepare("DELETE FROM shipment_docs WHERE id = ?")->execute([$id]);
            }
            echo json_encode(['success' => true]);
            break;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
