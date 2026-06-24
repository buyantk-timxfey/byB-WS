<?php
/**
 * deploy.php — серверный обработчик деплоя byB-workspace
 *
 * Принимает ZIP-архив, распаковывает файлы в корень проекта,
 * выполняет SQL-миграции, пишет лог.
 *
 * POST /deploy/deploy.php
 *   Header:  X-Deploy-Key: <secret>
 *   Body:    multipart/form-data  ->  zip=<file>
 */

// ─── Конфиг ──────────────────────────────────────────────────────────────────

define('DEPLOY_KEY', getenv('DEPLOY_KEY') ?: '1234');
define('PROJECT_ROOT',  realpath(__DIR__ . '/..') . '/');
define('LOG_FILE',      __DIR__ . '/deploy.log');
define('MAX_ZIP_SIZE',  50 * 1024 * 1024); // 50 МБ
define('BACKUP_ENABLED', true);
define('BACKUP_DIR',    __DIR__ . '/backups/');

// ─── Bootstrap ───────────────────────────────────────────────────────────────

@error_reporting(0);
@ini_set('display_errors', '0');
@set_time_limit(120);

header('Content-Type: application/json; charset=utf-8');

// ─── Помощники ────────────────────────────────────────────────────────────────

function respond(string $status, string $message, array $extra = []): void
{
    $payload = array_merge(['status' => $status, 'message' => $message], $extra);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function writeLog(string $level, string $message): void
{
    $line = sprintf(
        "[%s] [%s] [%s] %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        $message
    );
    file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function sanitizePath(string $path): string
{
    // Убираем ../ и ведущие слэши
    $path = str_replace(['../', '..\\', '../'], '', $path);
    return ltrim($path, '/\\');
}

function isPathSafe(string $absPath, string $root): bool
{
    return strpos(realpath(dirname($absPath)) . '/', realpath($root) . '/') === 0;
}

// ─── Авторизация ─────────────────────────────────────────────────────────────

$key = $_SERVER['HTTP_X_DEPLOY_KEY'] ?? ($_POST['key'] ?? '');

if (!hash_equals(DEPLOY_KEY, $key)) {
    writeLog('warn', 'Неверный ключ деплоя');
    http_response_code(403);
    respond('error', 'Неверный ключ авторизации');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    respond('error', 'Метод не поддерживается');
}

// ─── Проверка загруженного файла ──────────────────────────────────────────────

if (empty($_FILES['zip'])) {
    http_response_code(400);
    respond('error', 'ZIP файл не передан (поле: zip)');
}

$upload = $_FILES['zip'];

if ($upload['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'Файл превышает upload_max_filesize',
        UPLOAD_ERR_FORM_SIZE  => 'Файл превышает MAX_FILE_SIZE',
        UPLOAD_ERR_PARTIAL    => 'Файл загружен частично',
        UPLOAD_ERR_NO_FILE    => 'Файл не загружен',
        UPLOAD_ERR_NO_TMP_DIR => 'Нет временной директории',
        UPLOAD_ERR_CANT_WRITE => 'Ошибка записи на диск',
    ];
    http_response_code(400);
    respond('error', $uploadErrors[$upload['error']] ?? 'Ошибка загрузки файла');
}

if ($upload['size'] > MAX_ZIP_SIZE) {
    http_response_code(413);
    respond('error', 'Файл слишком большой (макс. 50 МБ)');
}

// Проверяем что это ZIP
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($upload['tmp_name']);
if (!in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'])) {
    // Ещё проверим magic bytes
    $fp      = fopen($upload['tmp_name'], 'rb');
    $magic   = fread($fp, 4);
    fclose($fp);
    if (substr($magic, 0, 2) !== "PK") {
        http_response_code(400);
        respond('error', 'Файл не является ZIP-архивом');
    }
}

// ─── Распаковка во временную папку ───────────────────────────────────────────

$tmpExtract = sys_get_temp_dir() . '/deploy_' . uniqid() . '/';
mkdir($tmpExtract, 0755, true);

$zip = new ZipArchive();
if ($zip->open($upload['tmp_name']) !== true) {
    rmdir($tmpExtract);
    http_response_code(422);
    respond('error', 'Не удалось открыть ZIP-архив');
}
$zip->extractTo($tmpExtract);
$zip->close();

// ─── Читаем manifest ──────────────────────────────────────────────────────────

$manifestPath = $tmpExtract . 'manifest.json';
$manifest     = [];
if (file_exists($manifestPath)) {
    $manifest = json_decode(file_get_contents($manifestPath), true) ?? [];
}

$gitCommit = $manifest['git_commit'] ?? 'unknown';
$gitBranch = $manifest['git_branch'] ?? 'unknown';
$builder   = $manifest['builder']    ?? 'unknown';
$createdAt = $manifest['created_at'] ?? date('c');

writeLog('info', "Деплой начат | branch={$gitBranch} commit={$gitCommit} builder={$builder}");

// ─── Список файлов для копирования ───────────────────────────────────────────

$filesDir = $tmpExtract . 'files/';
$sqlDir   = $tmpExtract . 'sql/';

$deployedFiles = [];
$errors        = [];
$backedUp      = [];

// ─── Бэкап изменяемых файлов ─────────────────────────────────────────────────

if (BACKUP_ENABLED && is_dir($filesDir)) {
    $backupRoot = BACKUP_DIR . date('Ymd-His') . '_' . $gitCommit . '/';
    @mkdir($backupRoot, 0755, true);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($filesDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        $relPath   = sanitizePath(substr($file->getPathname(), strlen($filesDir)));
        $targetAbs = PROJECT_ROOT . $relPath;

        if (file_exists($targetAbs)) {
            $backupDest = $backupRoot . $relPath;
            @mkdir(dirname($backupDest), 0755, true);
            copy($targetAbs, $backupDest);
            $backedUp[] = $relPath;
        }
    }

    if (!empty($backedUp)) {
        writeLog('info', 'Бэкап создан: ' . $backupRoot . ' (' . count($backedUp) . ' файлов)');
    }
}

// ─── Копирование файлов ───────────────────────────────────────────────────────

if (is_dir($filesDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($filesDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;

        $relPath   = sanitizePath(substr($file->getPathname(), strlen($filesDir)));
        $targetAbs = PROJECT_ROOT . $relPath;

        // Проверка безопасности пути
        if (!isPathSafe($targetAbs, PROJECT_ROOT)) {
            $errors[] = "Небезопасный путь пропущен: {$relPath}";
            writeLog('warn', "Небезопасный путь: {$relPath}");
            continue;
        }

        // Создаём директорию если нужно
        $targetDir = dirname($targetAbs);
        if (!is_dir($targetDir)) {
            if (!@mkdir($targetDir, 0755, true)) {
                $errors[] = "Не удалось создать директорию: " . dirname($relPath);
                continue;
            }
        }

        // Копируем файл
        if (@copy($file->getPathname(), $targetAbs)) {
            $deployedFiles[] = $relPath;
            writeLog('info', "Скопирован: {$relPath}");
        } else {
            $errors[] = "Не удалось скопировать: {$relPath}";
            writeLog('error', "Ошибка копирования: {$relPath}");
        }
    }
}

// ─── Выполнение SQL-миграций ──────────────────────────────────────────────────

$sqlExecuted = [];

if (is_dir($sqlDir)) {
    // Подключаемся к БД только если есть реальные SQL файлы
    $sqlFiles = glob($sqlDir . '*.sql');

    if (empty($sqlFiles)) {
        // SQL файлов нет — пропускаем подключение к БД
        goto skip_sql;
    }

    $dbConfigPath = PROJECT_ROOT . 'config.php';
    $pdo          = null;

    if (file_exists($dbConfigPath)) {
        try {
            // Дефолты
            $dbHost = 'localhost'; $dbUser = 'root'; $dbPass = ''; $dbName = 'byb_workspace';

            // Приоритет — секреты из config.local.php (массив), как их читает config.php
            $localConfigPath = PROJECT_ROOT . 'config.local.php';
            if (file_exists($localConfigPath)) {
                $local = @include $localConfigPath;
                if (is_array($local)) {
                    $dbHost = $local['DB_HOST'] ?? $dbHost;
                    $dbUser = $local['DB_USER'] ?? $dbUser;
                    $dbPass = $local['DB_PASS'] ?? $dbPass;
                    $dbName = $local['DB_NAME'] ?? $dbName;
                }
            } else {
                // Fallback: литеральные define() в config.php (старый формат)
                $configContent = file_get_contents($dbConfigPath);
                if (preg_match("/define\('DB_HOST',\s*'([^']+)'\)/", $configContent, $m)) $dbHost = $m[1];
                if (preg_match("/define\('DB_USER',\s*'([^']+)'\)/", $configContent, $m)) $dbUser = $m[1];
                if (preg_match("/define\('DB_PASS',\s*'([^']*)'\)/", $configContent, $m)) $dbPass = $m[1];
                if (preg_match("/define\('DB_NAME',\s*'([^']+)'\)/", $configContent, $m)) $dbName = $m[1];
            }

            $pdo = new PDO(
                "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (Exception $e) {
            $errors[] = 'Ошибка подключения к БД: ' . $e->getMessage();
            writeLog('error', 'Ошибка подключения к БД: ' . $e->getMessage());
        }
    }

    // Ищем SQL файлы в порядке сортировки
    $sqlFiles = glob($sqlDir . '*.sql');
    sort($sqlFiles);

    foreach ($sqlFiles as $sqlFile) {
        $sqlName = basename($sqlFile);
        $sqlContent = file_get_contents($sqlFile);

        if (empty(trim($sqlContent))) {
            continue;
        }

        if ($pdo === null) {
            $errors[] = "SQL пропущен (нет подключения к БД): {$sqlName}";
            continue;
        }

        // DDL (CREATE TABLE, ALTER TABLE) в MySQL нельзя откатить — не используем транзакцию.
        // Каждый запрос выполняется отдельно; "уже существует" считается успехом (идемпотентность).
        $statements = array_filter(
            array_map('trim', explode(';', $sqlContent)),
            fn($s) => !empty($s) && !preg_match('/^--/', $s)
        );

        $stmtErrors = [];
        foreach ($statements as $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (PDOException $e) {
                $code = (int)($e->errorInfo[1] ?? 0);
                // 1050 = Table already exists, 1060 = Duplicate column, 1061 = Duplicate key
                if (!in_array($code, [1050, 1060, 1061])) {
                    $stmtErrors[] = $e->getMessage();
                }
                // иначе — тихо пропускаем (идемпотентность)
            }
        }

        if (empty($stmtErrors)) {
            $sqlExecuted[] = $sqlName;
            writeLog('info', "SQL выполнен: {$sqlName} (" . count($statements) . " запросов)");
        } else {
            foreach ($stmtErrors as $err) {
                $errors[] = "SQL ошибка в {$sqlName}: " . $err;
            }
            writeLog('error', "SQL ошибки в {$sqlName}: " . implode('; ', $stmtErrors));
        }
    }

    skip_sql:
}

// ─── Сброс OPcache ────────────────────────────────────────────────────────────
// На PHP-FPM (reg.ru) перезаписанные .php могут продолжать отдаваться из OPcache
// (особенно при opcache.validate_timestamps=0). Сбрасываем, чтобы новый код
// применился сразу. Без этого симптом «залил, локально ок, на хосте старое».
$opcacheReset = false;
if (function_exists('opcache_reset')) {
    $opcacheReset = @opcache_reset();
    writeLog('info', 'OPcache сброшен: ' . ($opcacheReset ? 'да' : 'нет'));
}
if (function_exists('opcache_invalidate')) {
    foreach ($deployedFiles as $rel) {
        $abs = PROJECT_ROOT . $rel;
        if (substr($abs, -4) === '.php' && is_file($abs)) {
            @opcache_invalidate($abs, true);
        }
    }
}

// ─── Очистка временных файлов ─────────────────────────────────────────────────

$cleanIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmpExtract, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($cleanIterator as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
}
@rmdir($tmpExtract);

// ─── Ответ ────────────────────────────────────────────────────────────────────

$overallStatus = empty($errors) ? 'ok' : (empty($deployedFiles) ? 'error' : 'partial');

$summary = sprintf(
    'Задеплоено: %d файлов, SQL: %d, Ошибок: %d',
    count($deployedFiles),
    count($sqlExecuted),
    count($errors)
);

writeLog('info', "Деплой завершён | $summary");

http_response_code(200);
respond($overallStatus, $summary, [
    'deployed_files' => $deployedFiles,
    'sql_executed'   => $sqlExecuted,
    'backed_up'      => count($backedUp),
    'errors'         => $errors,
    'meta'           => [
        'git_branch'   => $gitBranch,
        'git_commit'   => $gitCommit,
        'builder'      => $builder,
        'deployed_at'  => date('Y-m-d H:i:s'),
        'opcache_reset'=> $opcacheReset,
    ],
]);
