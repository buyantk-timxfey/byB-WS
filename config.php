<?php
// ── Логирование ошибок (не показываем пользователю, но пишем в лог) ────────────
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/deploy/php-error.log');

// ── Секреты из config.local.php (вне git). Fallback на безопасные дефолты ──────
$__cfg = is_file(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : [];
define('DB_HOST',     $__cfg['DB_HOST']     ?? 'localhost');
define('DB_USER',     $__cfg['DB_USER']     ?? 'root');
define('DB_PASS',     $__cfg['DB_PASS']     ?? '');
define('DB_NAME',     $__cfg['DB_NAME']     ?? 'byb_workspace');
define('ADMIN_EMAIL', $__cfg['ADMIN_EMAIL'] ?? '');
define('ADMIN_HASH',  $__cfg['ADMIN_HASH']  ?? '');
unset($__cfg);

// ── Базовые security-заголовки (без CSP — на проде CSP задаётся отдельно,
//    чтобы не сломать inline-скрипты SPA) ──────────────────────────────────────
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// ── Подключение к БД ──────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    error_log('[byB] DB connect failed: ' . $e->getMessage());
    http_response_code(500);
    die(json_encode(['error' => 'Ошибка подключения к базе данных']));
}

// Чтобы GROUP_CONCAT не обрезал длинные списки позиций
$pdo->exec("SET SESSION group_concat_max_len = 1000000");

// ── Фикс для DELETE/PUT запросов — парсим параметры из URL ─────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
    parse_str(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $deletParams);
    foreach ($deletParams as $key => $value) {
        $_GET[$key] = $value;
    }
}

// ── Общие хелперы для API ──────────────────────────────────────────────────────

/**
 * Единый ответ об ошибке. Детали уходят в лог, клиент получает безопасное сообщение.
 */
function apiError(string $userMessage, ?\Throwable $e = null, int $code = 500): void {
    if ($e) {
        error_log('[byB] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    }
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => $userMessage]);
    exit;
}

/**
 * Проверяет наличие колонки в таблице через INFORMATION_SCHEMA.
 * Результат кэшируется в пределах запроса — заменяет хрупкий try/catch на INSERT/UPDATE.
 */
function columnExists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return $cache[$key] = (bool)$stmt->fetchColumn();
}

/**
 * Проверяет наличие таблицы в текущей БД (с кэшем в пределах запроса).
 */
function tableExists(PDO $pdo, string $table): bool {
    static $cache = [];
    if (array_key_exists($table, $cache)) return $cache[$table];
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return $cache[$table] = (bool)$stmt->fetchColumn();
}

// ── PIN-вход и запомненные устройства (банковский сценарий) ────────────────────

const DEVICE_COOKIE = 'byb_device';

/** Человекочитаемая метка устройства из User-Agent. */
function deviceLabelFromUA(): string {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (stripos($ua, 'iPhone')  !== false) return 'iPhone';
    if (stripos($ua, 'iPad')    !== false) return 'iPad';
    if (stripos($ua, 'Android') !== false) return 'Android';
    if (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS') !== false) return 'Mac';
    if (stripos($ua, 'Windows') !== false) return 'Windows';
    return 'Устройство';
}

/** Ставит httponly-куку токена устройства (на год). */
function setDeviceCookie(string $token): void {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    setcookie(DEVICE_COOKIE, $token, [
        'expires'  => time() + 31536000,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * Выдаёт устройству токен (если ещё нет валидного) после полного входа.
 * Вызывать ДО любого вывода. Идемпотентно: известное устройство просто обновляет last_used.
 */
function issueDeviceToken(PDO $pdo, ?string $label = null): void {
    if (!tableExists($pdo, 'device_tokens')) return;
    $existing = $_COOKIE[DEVICE_COOKIE] ?? '';
    if ($existing !== '') {
        $h = hash('sha256', $existing);
        $stmt = $pdo->prepare("SELECT id FROM device_tokens WHERE token_hash = ?");
        $stmt->execute([$h]);
        if ($stmt->fetchColumn()) {
            $pdo->prepare("UPDATE device_tokens SET last_used_at = NOW() WHERE token_hash = ?")->execute([$h]);
            return;
        }
    }
    $token = bin2hex(random_bytes(32));
    $pdo->prepare("INSERT INTO device_tokens (token_hash, label, last_used_at) VALUES (?, ?, NOW())")
        ->execute([hash('sha256', $token), $label ?? deviceLabelFromUA()]);
    setDeviceCookie($token);
}

/** Возвращает id записи device_tokens для текущей куки или null. */
function currentDeviceId(PDO $pdo): ?int {
    if (!tableExists($pdo, 'device_tokens')) return null;
    $t = $_COOKIE[DEVICE_COOKIE] ?? '';
    if ($t === '') return null;
    $stmt = $pdo->prepare("SELECT id FROM device_tokens WHERE token_hash = ?");
    $stmt->execute([hash('sha256', $t)]);
    $id = $stmt->fetchColumn();
    return $id ? (int)$id : null;
}

/** Задан ли PIN. */
function pinIsSet(PDO $pdo): bool {
    if (!tableExists($pdo, 'auth_pin')) return false;
    return (bool)$pdo->query("SELECT COUNT(*) FROM auth_pin")->fetchColumn();
}

/**
 * Проверка авторизации для API. Должна вызываться после session_start().
 */
function requireAuth(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['auth'])) {
        if (!headers_sent()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}
