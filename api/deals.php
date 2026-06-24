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

const DEAL_STAGES = ['Заявка', 'Поиск', 'Заказано', 'Завершено'];

/** Загружает варианты поиска для набора сделок. */
function loadDealOptions(PDO $pdo, array $dealIds): array {
    if (empty($dealIds)) return [];
    $in = implode(',', array_fill(0, count($dealIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM deal_options WHERE deal_id IN ($in) ORDER BY (price + delivery_cost) ASC, id ASC");
    $stmt->execute($dealIds);
    $result = [];
    foreach ($stmt->fetchAll() as $row) $result[$row['deal_id']][] = $row;
    return $result;
}

try {
    switch ($method) {

        case 'GET':
            $where  = [];
            $params = [];
            if (isset($_GET['id'])) { $where[] = 'd.id = ?'; $params[] = (int)$_GET['id']; }
            if (!empty($_GET['active'])) { $where[] = "d.stage != 'Завершено'"; }
            $whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            $stmt = $pdo->prepare("
                SELECT d.*, CONCAT(COALESCE(c.company_type,''), ' ', COALESCE(c.name,'')) as counterparty_name,
                       s.status as shipment_status, s.eta as shipment_eta
                FROM deals d
                LEFT JOIN counterparties c ON c.id = d.counterparty_id
                LEFT JOIN shipments s ON s.id = d.shipment_id
                $whereStr
                ORDER BY (d.deadline IS NULL), d.deadline ASC, d.created_at DESC
            ");
            $stmt->execute($params);
            $deals = $stmt->fetchAll();

            $optionsByDeal = loadDealOptions($pdo, array_column($deals, 'id'));
            foreach ($deals as &$d) {
                $d['counterparty_name'] = trim($d['counterparty_name'] ?? '');
                $d['options'] = $optionsByDeal[$d['id']] ?? [];
            }
            unset($d);

            echo json_encode(isset($_GET['id']) ? ($deals[0] ?? null) : $deals);
            break;

        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true) ?? [];
            $action = $data['action'] ?? 'create';

            switch ($action) {

                case 'create':
                    $title = trim($data['title'] ?? '');
                    if ($title === '') apiError('Укажите, что ищем', null, 400);
                    $pdo->prepare("
                        INSERT INTO deals (title, quantity, counterparty_id, competitor_price, stage, note, deadline)
                        VALUES (?, ?, ?, ?, 'Заявка', ?, ?)
                    ")->execute([
                        $title,
                        max(1, (int)($data['quantity'] ?? 1)),
                        !empty($data['counterparty_id']) ? (int)$data['counterparty_id'] : null,
                        $data['competitor_price'] !== '' && isset($data['competitor_price']) ? (float)$data['competitor_price'] : null,
                        trim($data['note'] ?? '') ?: null,
                        !empty($data['deadline']) ? $data['deadline'] : null,
                    ]);
                    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
                    break;

                case 'update':
                    $id = (int)($data['id'] ?? 0);
                    if (!$id) apiError('Неверные параметры', null, 400);
                    $pdo->prepare("
                        UPDATE deals SET title=?, quantity=?, counterparty_id=?, competitor_price=?, note=?, deadline=?
                        WHERE id=?
                    ")->execute([
                        trim($data['title'] ?? ''),
                        max(1, (int)($data['quantity'] ?? 1)),
                        !empty($data['counterparty_id']) ? (int)$data['counterparty_id'] : null,
                        $data['competitor_price'] !== '' && isset($data['competitor_price']) ? (float)$data['competitor_price'] : null,
                        trim($data['note'] ?? '') ?: null,
                        !empty($data['deadline']) ? $data['deadline'] : null,
                        $id,
                    ]);
                    echo json_encode(['success' => true]);
                    break;

                case 'set_stage':
                    $id    = (int)($data['id'] ?? 0);
                    $stage = $data['stage'] ?? '';
                    if (!$id || !in_array($stage, DEAL_STAGES, true)) apiError('Неверные параметры', null, 400);
                    $pdo->prepare("UPDATE deals SET stage=? WHERE id=?")->execute([$stage, $id]);
                    echo json_encode(['success' => true]);
                    break;

                case 'add_option':
                    $dealId = (int)($data['deal_id'] ?? 0);
                    if (!$dealId) apiError('Неверные параметры', null, 400);
                    $pdo->prepare("
                        INSERT INTO deal_options (deal_id, supplier, url, price, delivery_cost, delivery_days, note)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $dealId,
                        trim($data['supplier'] ?? '') ?: null,
                        trim($data['url'] ?? '') ?: null,
                        (float)($data['price'] ?? 0),
                        (float)($data['delivery_cost'] ?? 0),
                        isset($data['delivery_days']) && $data['delivery_days'] !== '' ? (int)$data['delivery_days'] : null,
                        trim($data['note'] ?? '') ?: null,
                    ]);
                    // Сделка с вариантами автоматически переходит из «Заявки» в «Поиск»
                    $pdo->prepare("UPDATE deals SET stage='Поиск' WHERE id=? AND stage='Заявка'")->execute([$dealId]);
                    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
                    break;

                case 'delete_option':
                    $pdo->prepare("DELETE FROM deal_options WHERE id=?")->execute([(int)($data['id'] ?? 0)]);
                    echo json_encode(['success' => true]);
                    break;

                case 'choose_option':
                    $optId = (int)($data['id'] ?? 0);
                    $st = $pdo->prepare("SELECT deal_id FROM deal_options WHERE id=?");
                    $st->execute([$optId]);
                    $dealId = (int)$st->fetchColumn();
                    if (!$dealId) apiError('Вариант не найден', null, 400);
                    $pdo->prepare("UPDATE deal_options SET is_chosen=0 WHERE deal_id=?")->execute([$dealId]);
                    $pdo->prepare("UPDATE deal_options SET is_chosen=1 WHERE id=?")->execute([$optId]);
                    echo json_encode(['success' => true]);
                    break;

                case 'link_shipment':
                    $id         = (int)($data['id'] ?? 0);
                    $shipmentId = (int)($data['shipment_id'] ?? 0);
                    if (!$id || !$shipmentId) apiError('Неверные параметры', null, 400);
                    $pdo->prepare("UPDATE deals SET shipment_id=?, stage='Заказано' WHERE id=?")
                        ->execute([$shipmentId, $id]);
                    echo json_encode(['success' => true]);
                    break;

                default:
                    apiError('Неизвестное действие', null, 400);
            }
            break;

        case 'DELETE':
            $pdo->prepare("DELETE FROM deals WHERE id=?")->execute([(int)($_GET['id'] ?? 0)]);
            echo json_encode(['success' => true]);
            break;
    }
} catch (Throwable $e) {
    apiError('Ошибка сервера', $e);
}
