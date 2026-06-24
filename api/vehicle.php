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

        case 'GET': {
            // Расходы ИП для привязки (ещё не связанные с заправками/пополнениями)
            if (isset($_GET['expenses'])) {
                $q = trim($_GET['q'] ?? '');
                $sql = "
                    SELECT be.id, be.name, be.amount, be.expense_date, ec.name AS category_name
                    FROM business_expenses be
                    LEFT JOIN expense_categories ec ON ec.id = be.category_id
                    WHERE be.id NOT IN (SELECT expense_id FROM fuel_ups WHERE expense_id IS NOT NULL)
                      AND be.id NOT IN (SELECT expense_id FROM fuel_card_topups)
                ";
                $params = [];
                if ($q !== '') {
                    $sql .= " AND (be.name LIKE ? OR ec.name LIKE ?)";
                    $params[] = "%$q%"; $params[] = "%$q%";
                }
                $sql .= " ORDER BY be.expense_date DESC, be.id DESC LIMIT 300";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                echo json_encode($stmt->fetchAll());
                break;
            }
            echo json_encode(['error' => 'Unknown GET']);
            break;
        }

        case 'POST': {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $entity = $data['entity'] ?? '';

            if ($entity === 'trip') {
                if (empty($data['date_from'])) { echo json_encode(['error' => 'Укажите дату']); break; }
                $pdo->prepare("INSERT INTO vehicle_trips (date_from, odometer, note) VALUES (?,?,?)")
                    ->execute([
                        $data['date_from'],
                        (int)($data['odometer'] ?? 0),
                        $data['note'] ?? null,
                    ]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);

            } elseif ($entity === 'wash') {
                if (empty($data['wash_date'])) { echo json_encode(['error' => 'Укажите дату']); break; }
                $pdo->prepare("INSERT INTO car_washes (wash_date, amount, note) VALUES (?,?,?)")
                    ->execute([$data['wash_date'], (float)($data['amount'] ?? 0), $data['note'] ?? null]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);

            } elseif ($entity === 'fuelup') {
                if (empty($data['fuel_date'])) { echo json_encode(['error' => 'Укажите дату']); break; }
                $card = ($data['card_type'] ?? 'Топливная') === 'Обычная' ? 'Обычная' : 'Топливная';
                // Привязка к расходу ИП только для обычной карты (топливная уже оплачена при пополнении)
                $expenseId = ($card === 'Обычная' && !empty($data['expense_id'])) ? (int)$data['expense_id'] : null;
                $pdo->prepare("INSERT INTO fuel_ups (fuel_date, liters, amount, card_type, expense_id, note) VALUES (?,?,?,?,?,?)")
                    ->execute([
                        $data['fuel_date'],
                        (float)($data['liters'] ?? 0),
                        (float)($data['amount'] ?? 0),
                        $card,
                        $expenseId,
                        $data['note'] ?? null,
                    ]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);

            } elseif ($entity === 'topup') {
                $eid = (int)($data['expense_id'] ?? 0);
                if (!$eid) { echo json_encode(['error' => 'Выберите расход']); break; }
                $pdo->prepare("INSERT IGNORE INTO fuel_card_topups (expense_id) VALUES (?)")->execute([$eid]);
                echo json_encode(['success' => true]);

            } else {
                echo json_encode(['error' => 'Unknown entity']);
            }
            break;
        }

        case 'PUT': {
            $data = json_decode(file_get_contents('php://input'), true) ?: [];
            $entity = $data['entity'] ?? '';

            if ($entity === 'trip') {
                $pdo->prepare("UPDATE vehicle_trips SET date_from=?, odometer=?, note=? WHERE id=?")
                    ->execute([
                        $data['date_from'],
                        (int)($data['odometer'] ?? 0),
                        $data['note'] ?? null,
                        (int)$data['id'],
                    ]);
                echo json_encode(['success' => true]);

            } elseif ($entity === 'wash') {
                $pdo->prepare("UPDATE car_washes SET wash_date=?, amount=?, note=? WHERE id=?")
                    ->execute([$data['wash_date'], (float)($data['amount'] ?? 0), $data['note'] ?? null, (int)$data['id']]);
                echo json_encode(['success' => true]);

            } elseif ($entity === 'fuelup') {
                $card = ($data['card_type'] ?? 'Топливная') === 'Обычная' ? 'Обычная' : 'Топливная';
                $expenseId = ($card === 'Обычная' && !empty($data['expense_id'])) ? (int)$data['expense_id'] : null;
                $pdo->prepare("UPDATE fuel_ups SET fuel_date=?, liters=?, amount=?, card_type=?, expense_id=?, note=? WHERE id=?")
                    ->execute([
                        $data['fuel_date'],
                        (float)($data['liters'] ?? 0),
                        (float)($data['amount'] ?? 0),
                        $card,
                        $expenseId,
                        $data['note'] ?? null,
                        (int)$data['id'],
                    ]);
                echo json_encode(['success' => true]);

            } elseif ($entity === 'settings') {
                $exists = $pdo->query("SELECT id FROM vehicle_settings LIMIT 1")->fetchColumn();
                $rate  = (float)($data['consumption_rate'] ?? 8.8);
                $start = (int)($data['start_odometer'] ?? 0);
                if ($exists) {
                    $pdo->prepare("UPDATE vehicle_settings SET consumption_rate=?, start_odometer=? WHERE id=?")
                        ->execute([$rate, $start, $exists]);
                } else {
                    $pdo->prepare("INSERT INTO vehicle_settings (consumption_rate, start_odometer) VALUES (?,?)")
                        ->execute([$rate, $start]);
                }
                echo json_encode(['success' => true]);

            } else {
                echo json_encode(['error' => 'Unknown entity']);
            }
            break;
        }

        case 'DELETE': {
            $entity = $_GET['entity'] ?? '';
            $id = (int)($_GET['id'] ?? 0);
            if ($entity === 'trip') {
                $pdo->prepare("DELETE FROM vehicle_trips WHERE id=?")->execute([$id]);
            } elseif ($entity === 'wash') {
                $pdo->prepare("DELETE FROM car_washes WHERE id=?")->execute([$id]);
            } elseif ($entity === 'fuelup') {
                $pdo->prepare("DELETE FROM fuel_ups WHERE id=?")->execute([$id]);
            } elseif ($entity === 'topup') {
                $pdo->prepare("DELETE FROM fuel_card_topups WHERE id=?")->execute([$id]);
            } else {
                echo json_encode(['error' => 'Unknown entity']); break;
            }
            echo json_encode(['success' => true]);
            break;
        }
    }
} catch (Throwable $e) {
    apiError('Ошибка сервера', $e);
}
