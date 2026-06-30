<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use ZipArchive;

// Деплой патча из ZIP прямо из интерфейса (только для залогиненного админа).
// Распаковывает архив поверх приложения, применяет миграции и сбрасывает кэш.
// .env и папка storage/ намеренно не перезаписываются.
class DeployController extends Controller
{
    public function apply(Request $request)
    {
        $request->validate(['archive' => 'required|file|max:102400']); // до 100 МБ

        $tmp = $request->file('archive')->getRealPath();
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            return back()->withErrors(['archive' => 'Не удалось открыть ZIP-архив.']);
        }

        $base = rtrim(base_path(), '/');
        $applied = 0;
        $skipped = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || str_ends_with($name, '/')) {
                continue; // папки создадим сами
            }
            $rel = ltrim(str_replace('\\', '/', $name), '/');

            // защита: запрет выхода за пределы и перезапись секретов/данных
            if ($rel === '' || str_contains($rel, '..')) { $skipped++; continue; }
            if ($rel === '.env' || str_starts_with($rel, '.env')) { $skipped++; continue; }
            if (str_starts_with($rel, 'storage/') && ! str_starts_with($rel, 'storage/app/public/')) { $skipped++; continue; }

            $dest = $base.'/'.$rel;
            $dir = dirname($dest);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $data = $zip->getFromIndex($i);
            if ($data !== false && @file_put_contents($dest, $data) !== false) {
                $applied++;
            } else {
                $skipped++;
            }
        }
        $zip->close();

        // Применяем миграции и чистим кэш
        $migrateOut = '';
        try {
            Artisan::call('migrate', ['--force' => true]);
            $migrateOut = trim(Artisan::output());
            Artisan::call('config:clear');
            Artisan::call('view:clear');
            Artisan::call('cache:clear');
            Artisan::call('route:clear');
        } catch (\Throwable $e) {
            report($e);
        }

        // Сбрасываем OPcache чтобы PHP сразу увидел новые файлы
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        $summary = 'применено '.$applied.' · пропущено '.$skipped.' · '.now()->format('d.m.Y H:i');
        Setting::put('last_patch', $summary);

        return back()->with('deploy', [
            'applied' => $applied, 'skipped' => $skipped,
            'migrate' => $migrateOut ?: 'нет новых миграций',
        ]);
    }
}
