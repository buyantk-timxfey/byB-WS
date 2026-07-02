<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

// Резервные копии БД: чистый PHP-дамп (mysqldump на хостинге недоступен),
// gzip в storage/app/backups, хранятся последние 14 копий.
class DbBackup
{
    public const KEEP = 14;

    public static function dir(): string
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    public static function run(): string
    {
        $name = 'backup-'.now()->format('Ymd-His').'.sql.gz';
        $path = self::dir().'/'.$name;

        if (DB::getDriverName() === 'sqlite') {
            file_put_contents($path, gzencode((string) file_get_contents(DB::getDatabaseName()), 6));
        } else {
            self::dumpMysql($path);
        }

        self::prune();
        Setting::put('last_backup_at', now()->toDateTimeString());

        return $name;
    }

    private static function dumpMysql(string $path): void
    {
        $gz = gzopen($path, 'wb6');
        $pdo = DB::connection()->getPdo();
        gzwrite($gz, "-- byBuka backup ".now()->toDateTimeString()."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = array_map(fn ($r) => current((array) $r), DB::select('SHOW TABLES'));
        foreach ($tables as $t) {
            $create = DB::select('SHOW CREATE TABLE `'.$t.'`')[0];
            $createSql = ((array) $create)['Create Table'] ?? null;
            if (! $createSql) {
                continue;   // view или служебный объект — пропускаем
            }
            gzwrite($gz, "DROP TABLE IF EXISTS `{$t}`;\n{$createSql};\n");

            $buf = '';
            foreach (DB::table($t)->cursor() as $row) {
                $vals = array_map(
                    fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                    (array) $row,
                );
                $buf .= "INSERT INTO `{$t}` VALUES (".implode(',', $vals).");\n";
                if (strlen($buf) > 262144) {
                    gzwrite($gz, $buf);
                    $buf = '';
                }
            }
            gzwrite($gz, $buf."\n");
        }

        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
    }

    // Хранить только последние KEEP копий
    private static function prune(): void
    {
        $files = glob(self::dir().'/backup-*.sql.gz') ?: [];
        sort($files);
        foreach (array_slice($files, 0, max(0, count($files) - self::KEEP)) as $old) {
            @unlink($old);
        }
    }

    /** @return array<int, array{name: string, size: int, date: string}> — свежие сверху */
    public static function list(): array
    {
        $files = glob(self::dir().'/backup-*.sql.gz') ?: [];
        rsort($files);

        return array_map(fn ($f) => [
            'name' => basename($f),
            'size' => (int) filesize($f),
            'date' => date('Y-m-d H:i', (int) filemtime($f)),
        ], $files);
    }

    // Раз в сутки, без крона: дёргается после ответа (terminate-мидлвар)
    public static function runIfDue(): void
    {
        $last = Setting::get('last_backup_at');
        if ($last && \Illuminate\Support\Carbon::parse($last)->diffInHours(now()) < 24) {
            return;
        }
        try {
            self::run();
        } catch (\Throwable $e) {
            error_log('DbBackup failed: '.$e->getMessage());
        }
    }
}
