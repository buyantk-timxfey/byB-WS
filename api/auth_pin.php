<?php
require_once '../config.php';
session_start();
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {

        // ── Проверка PIN (вход). Работает только на запомненном устройстве. ──────
        case 'verify': {
            if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); break; }

            // Rate-limit: 5 попыток / 15 минут на сессию
            $now = time();
            if (!isset($_SESSION['pin_attempts'])) $_SESSION['pin_attempts'] = ['count' => 0, 'first' => $now];
            $att = &$_SESSION['pin_attempts'];
            if ($now - $att['first'] > 900) $att = ['count' => 0, 'first' => $now];
            if ($att['count'] >= 5) {
                echo json_encode(['ok' => false, 'locked' => true, 'error' => 'Слишком много попыток. Войдите паролем.']);
                break;
            }

            // PIN действует только на устройстве, прошедшем полный вход
            if (currentDeviceId($pdo) === null) {
                echo json_encode(['ok' => false, 'fallback' => true, 'error' => 'Устройство не распознано. Войдите паролем.']);
                break;
            }
            if (!pinIsSet($pdo)) {
                echo json_encode(['ok' => false, 'fallback' => true, 'error' => 'PIN не задан.']);
                break;
            }

            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $pin  = (string)($body['pin'] ?? '');
            $hash = $pdo->query("SELECT pin_hash FROM auth_pin ORDER BY id DESC LIMIT 1")->fetchColumn();

            if ($hash && preg_match('/^\d{4}$/', $pin) && password_verify($pin, $hash)) {
                session_regenerate_id(true);
                $_SESSION['auth'] = true;
                unset($_SESSION['pin_attempts'], $_SESSION['login_attempts']);
                $t = $_COOKIE[DEVICE_COOKIE] ?? '';
                if ($t !== '') {
                    $pdo->prepare("UPDATE device_tokens SET last_used_at = NOW() WHERE token_hash = ?")
                        ->execute([hash('sha256', $t)]);
                }
                echo json_encode(['ok' => true]);
            } else {
                $att['count']++;
                echo json_encode(['ok' => false, 'error' => 'Неверный PIN', 'left' => max(0, 5 - $att['count'])]);
            }
            break;
        }

        // ── Установить/сменить PIN (только залогиненный) ─────────────────────────
        case 'set': {
            requireAuth();
            if ($method !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); break; }
            $body = json_decode(file_get_contents('php://input'), true) ?: [];
            $pin  = (string)($body['pin'] ?? '');
            if (!preg_match('/^\d{4}$/', $pin)) {
                echo json_encode(['ok' => false, 'error' => 'PIN — ровно 4 цифры']);
                break;
            }
            $hash = password_hash($pin, PASSWORD_DEFAULT);
            $pdo->exec("DELETE FROM auth_pin");
            $pdo->prepare("INSERT INTO auth_pin (pin_hash) VALUES (?)")->execute([$hash]);
            // Текущее устройство должно стать запомненным, чтобы PIN тут работал
            issueDeviceToken($pdo);
            echo json_encode(['ok' => true]);
            break;
        }

        // ── Отключить PIN ────────────────────────────────────────────────────────
        case 'disable': {
            requireAuth();
            $pdo->exec("DELETE FROM auth_pin");
            echo json_encode(['ok' => true]);
            break;
        }

        // ── Забыть текущее устройство ────────────────────────────────────────────
        case 'forget': {
            requireAuth();
            $t = $_COOKIE[DEVICE_COOKIE] ?? '';
            if ($t !== '') {
                $pdo->prepare("DELETE FROM device_tokens WHERE token_hash = ?")->execute([hash('sha256', $t)]);
                setcookie(DEVICE_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
            }
            echo json_encode(['ok' => true]);
            break;
        }

        // ── Забыть все устройства ────────────────────────────────────────────────
        case 'forget_all': {
            requireAuth();
            $pdo->exec("DELETE FROM device_tokens");
            setcookie(DEVICE_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
            echo json_encode(['ok' => true]);
            break;
        }

        // ── Статус для экрана настроек ───────────────────────────────────────────
        case 'status': {
            requireAuth();
            $curId = currentDeviceId($pdo);
            $devices = $pdo->query("SELECT id, label, created_at, last_used_at FROM device_tokens ORDER BY last_used_at DESC, id DESC")->fetchAll();
            foreach ($devices as &$d) { $d['current'] = ((int)$d['id'] === $curId); }
            echo json_encode([
                'pinSet'      => pinIsSet($pdo),
                'deviceKnown' => $curId !== null,
                'devices'     => $devices,
            ]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    apiError('Ошибка сервера', $e);
}
