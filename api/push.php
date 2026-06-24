<?php
session_start();
if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
    http_response_code(401); exit;
}

require_once '../config.php';
header('Content-Type: application/json; charset=utf-8');

// Загружаем VAPID ключи если есть
$vapidPath = __DIR__ . '/../vapid.php';
if (file_exists($vapidPath)) require_once $vapidPath;

// Создаём таблицу подписок
$pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    endpoint     VARCHAR(1000) NOT NULL,
    p256dh       VARCHAR(500)  NOT NULL,
    auth_key     VARCHAR(200)  NOT NULL,
    user_agent   VARCHAR(300),
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_endpoint (endpoint(255))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// ─── Подписка ─────────────────────────────────────────────────────────────────
if ($action === 'subscribe') {
    $sub    = $body['subscription'] ?? [];
    $ep     = $sub['endpoint']                  ?? '';
    $p256dh = $sub['keys']['p256dh']             ?? '';
    $auth   = $sub['keys']['auth']               ?? '';
    $ua     = $_SERVER['HTTP_USER_AGENT']        ?? '';

    if (!$ep || !$p256dh || !$auth) {
        http_response_code(400);
        echo json_encode(['error' => 'Неполные данные подписки']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO push_subscriptions (endpoint, p256dh, auth_key, user_agent)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth_key = VALUES(auth_key)
    ");
    $stmt->execute([$ep, $p256dh, $auth, mb_substr($ua, 0, 300)]);

    echo json_encode(['ok' => true]);
    exit;
}

// ─── Отписка ──────────────────────────────────────────────────────────────────
if ($action === 'unsubscribe') {
    $ep = $body['endpoint'] ?? '';
    if ($ep) {
        $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint = ?")->execute([$ep]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ─── Отправка push всем подписчикам (вызывается внутренне) ───────────────────
if ($action === 'send' && defined('VAPID_PUBLIC_KEY') && defined('VAPID_PRIVATE_KEY')) {
    $title   = $body['title']   ?? 'byBuka';
    $message = $body['message'] ?? '';
    $page    = $body['page']    ?? null;
    $tag     = $body['tag']     ?? 'byb';

    $subs = $pdo->query("SELECT * FROM push_subscriptions")->fetchAll(PDO::FETCH_ASSOC);

    $sent   = 0;
    $failed = [];

    foreach ($subs as $sub) {
        $result = sendWebPush(
            $sub['endpoint'],
            $sub['p256dh'],
            $sub['auth_key'],
            json_encode([
                'title'   => $title,
                'body'    => $message,
                'tag'     => $tag,
                'icon'    => './assets/img/icon-192.png',
                'badge'   => './assets/img/icon-96.png',
                'data'    => ['page' => $page],
            ])
        );

        if ($result === true) {
            $sent++;
        } else {
            // Удаляем устаревшие подписки (410 Gone)
            if ($result === 410) {
                $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?")->execute([$sub['id']]);
            }
            $failed[] = $sub['id'];
        }
    }

    echo json_encode(['sent' => $sent, 'failed' => count($failed)]);
    exit;
}

// ─── Статус ───────────────────────────────────────────────────────────────────
if ($action === 'status') {
    $count = $pdo->query("SELECT COUNT(*) FROM push_subscriptions")->fetchColumn();
    echo json_encode([
        'subscriptions' => (int)$count,
        'vapid_ready'   => defined('VAPID_PUBLIC_KEY'),
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Неизвестное действие']);

// ─── Отправка Web Push (VAPID) ────────────────────────────────────────────────

function sendWebPush(string $endpoint, string $p256dh, string $authKey, string $payload): bool|int
{
    if (!defined('VAPID_PUBLIC_KEY') || !defined('VAPID_PRIVATE_KEY')) return false;

    // Заголовки VAPID (JWT)
    $origin   = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
    $expiry   = time() + 12 * 3600;

    $header   = rtrim(strtr(base64_encode(json_encode(['typ'=>'JWT','alg'=>'ES256'])), '+/', '-_'), '=');
    $claims   = rtrim(strtr(base64_encode(json_encode(['aud'=>$origin,'exp'=>$expiry,'sub'=>defined('VAPID_SUBJECT') ? VAPID_SUBJECT : 'mailto:admin@example.com'])), '+/', '-_'), '=');
    $unsigned = "$header.$claims";

    // Подписываем ES256 (openssl)
    $privDer  = base64_decode(strtr(VAPID_PRIVATE_KEY, '-_', '+/'));
    $key      = openssl_pkey_new([
        'curve_name'       => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);

    // Используем сохранённый приватный ключ через PKCS8
    $privPem = "-----BEGIN EC PRIVATE KEY-----\n"
        . chunk_split(base64_encode(
            // SEC1 DER encoding для P-256
            "\x30\x77\x02\x01\x01\x04\x20" . $privDer
            . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
        ), 64, "\n")
        . "-----END EC PRIVATE KEY-----\n";

    $privKey = openssl_pkey_get_private($privPem);
    if (!$privKey) return false;

    openssl_sign($unsigned, $sig, $privKey, OPENSSL_ALGO_SHA256);

    // DER → raw r||s (64 байта)
    $r_len = ord($sig[3]);
    $r     = substr($sig, 4, $r_len);
    $s_len = ord($sig, 4 + $r_len + 1);
    $s     = substr($sig, 4 + $r_len + 2);
    $r     = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s     = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $rawSig = rtrim(strtr(base64_encode($r . $s), '+/', '-_'), '=');

    $jwt = "$unsigned.$rawSig";

    $authHeader = 'vapid t=' . $jwt . ', k=' . VAPID_PUBLIC_KEY;

    // Шифрование payload (aes128gcm) — базовая реализация
    // Для продакшна рекомендуется minishlink/web-push
    $encryptedPayload = encryptPayload($payload, $p256dh, $authKey);
    if (!$encryptedPayload) return false;

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $encryptedPayload['ciphertext'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 86400',
            'Authorization: ' . $authHeader,
            'Content-Length: ' . strlen($encryptedPayload['ciphertext']),
        ],
    ]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 201 || $httpCode === 200) return true;
    if ($httpCode === 410 || $httpCode === 404) return 410; // Подписка устарела
    return false;
}

function encryptPayload(string $payload, string $p256dh, string $authKey): array|false
{
    if (!function_exists('openssl_random_pseudo_bytes')) return false;

    // aes128gcm шифрование для Web Push
    // Генерируем эфемерную EC пару
    $localKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$localKey) return false;

    $localDetails  = openssl_pkey_get_details($localKey);
    $localPubPoint = "\x04"
        . str_pad($localDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT)
        . str_pad($localDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);

    // Декодируем публичный ключ подписчика
    $receiverPub = base64_decode(strtr($p256dh, '-_', '+/'));
    $authKeyRaw  = base64_decode(strtr($authKey, '-_', '+/'));
    $salt        = openssl_random_pseudo_bytes(16);

    // ECDH shared secret
    $receiverKey = openssl_pkey_get_public([
        'ec' => [
            'curve_name' => 'prime256v1',
            'x'          => substr($receiverPub, 1, 32),
            'y'          => substr($receiverPub, 33, 32),
        ],
    ]);
    if (!$receiverKey) return false;

    openssl_dh_compute_key($sharedSecret, $receiverKey, $localKey);

    // HKDF для вычисления ключей
    $prk    = hash_hmac('sha256', $sharedSecret, $authKeyRaw, true);
    $keyInfo = "WebPush: info\x00" . $receiverPub . $localPubPoint;
    $ikm    = substr(hash_hmac('sha256', "\x01", hash_hmac('sha256', $keyInfo, $prk, true), true), 0, 32);

    $prkSalt = hash_hmac('sha256', $salt, $ikm, true);
    $key     = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prkSalt, true), 0, 16);
    $nonce   = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01",     $prkSalt, true), 0, 12);

    // Добавляем padding (минимальный: 1 байт \x02)
    $record   = $payload . "\x02";
    $encrypted = openssl_encrypt($record, 'aes-128-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($encrypted === false) return false;

    // aes128gcm заголовок + шифртекст + тег
    $header = $salt                          // 16 байт
        . pack('N', 4096)                    // rs = 4096
        . "\x41"                             // idlen = 65
        . $localPubPoint;                    // sender public key

    return ['ciphertext' => $header . $encrypted . $tag];
}
