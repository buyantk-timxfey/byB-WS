<?php
/**
 * Бэкап базы данных в backups_db/byb-ГГГГММДД-ЧЧММСС.sql.gz.
 * Хранятся последние 14 копий, старые удаляются автоматически.
 *
 * Запуск:
 *  - Cron (ISPmanager → Планировщик): php /путь/к/сайту/db_backup.php  (ежедневно)
 *  - Вручную из браузера: /db_backup.php (нужна активная сессия — просто будь залогинен)
 */

$isCli = php_sapi_name() === 'cli';
if ($isCli) {
    // В CLI рабочая директория произвольная — переходим в папку скрипта
    chdir(__DIR__);
}
require_once __DIR__ . '/config.php';

if (!$isCli) {
    session_start();
    if (!isset($_SESSION['auth']) || $_SESSION['auth'] !== true) {
        http_response_code(401);
        echo 'Unauthorized';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

const BACKUP_KEEP = 14;

$dir = __DIR__ . '/backups_db';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
// Защита папки от прямого доступа из веба
$ht = $dir . '/.htaccess';
if (!is_file($ht)) {
    file_put_contents($ht, "Order deny,allow\nDeny from all\n");
}

$file = $dir . '/byb-' . date('Ymd-His') . '.sql.gz';
$gz   = gzopen($file, 'wb6');
if (!$gz) {
    echo "Не удалось создать файл бэкапа\n";
    exit(1);
}

gzwrite($gz, "-- byBuka Workspace DB backup " . date('Y-m-d H:i:s') . "\n");
gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$totalRows = 0;

foreach ($tables as $table) {
    $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
    gzwrite($gz, "DROP TABLE IF EXISTS `$table`;\n$create;\n\n");

    $stmt = $pdo->query("SELECT * FROM `$table`");
    $batch = [];
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $vals = array_map(function ($v) use ($pdo) {
            if ($v === null) return 'NULL';
            return $pdo->quote((string)$v);
        }, $row);
        $batch[] = '(' . implode(',', $vals) . ')';
        $totalRows++;
        if (count($batch) >= 200) {
            gzwrite($gz, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
            $batch = [];
        }
    }
    if ($batch) {
        gzwrite($gz, "INSERT INTO `$table` VALUES\n" . implode(",\n", $batch) . ";\n");
    }
    gzwrite($gz, "\n");
}

gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
gzclose($gz);

// Ротация: оставляем последние BACKUP_KEEP файлов
$all = glob($dir . '/byb-*.sql.gz');
rsort($all); // новые первыми (имена с датой сортируются лексикографически)
foreach (array_slice($all, BACKUP_KEEP) as $old) {
    @unlink($old);
}

$size = round(filesize($file) / 1024, 1);
echo "OK: " . basename($file) . " ({$size} КБ, таблиц: " . count($tables) . ", строк: {$totalRows}, копий: " . min(count($all), BACKUP_KEEP) . ")\n";
