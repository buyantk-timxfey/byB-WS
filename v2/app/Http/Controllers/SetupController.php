<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

// Одноразовый веб-установщик: создаёт таблицы и первый аккаунт без доступа к консоли.
// Защита: токен из .env (INSTALL_TOKEN) + отказ, если уже установлено.
class SetupController extends Controller
{
    public function run(Request $request)
    {
        $token = config('app.install_token');
        abort_unless($token && hash_equals((string) $token, (string) $request->query('key')), 403, 'Доступ запрещён');

        if (Schema::hasTable('users') && User::count() > 0) {
            return $this->page('Уже установлено',
                'Таблицы и аккаунт уже созданы. Откройте <a href="/">страницу входа</a>.<br><br>'
                .'Для безопасности удалите строку <code>INSTALL_TOKEN</code> из файла <code>.env</code>.');
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('db:seed', ['--force' => true]);
            try {
                Artisan::call('storage:link');
            } catch (\Throwable $e) {
                // не критично
            }
        } catch (\Throwable $e) {
            return $this->page('Ошибка установки',
                'Не удалось создать таблицы:<br><br><code>'.e($e->getMessage()).'</code><br><br>'
                .'Проверьте в <code>.env</code> данные базы (DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_HOST).');
        }

        return $this->page('Установка завершена ✓',
            'Таблицы созданы, первый аккаунт добавлен.<br><br>'
            .'Откройте <a href="/">страницу входа</a> и войдите данными из <code>.env</code> '
            .'(ADMIN_EMAIL / ADMIN_PASSWORD, PIN из ADMIN_PIN).<br><br>'
            .'<b>Важно:</b> удалите строку <code>INSTALL_TOKEN</code> из <code>.env</code>, чтобы закрыть установщик.');
    }

    private function page(string $title, string $body)
    {
        $html = '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>byBuka · установка</title>'
            .'<style>body{font-family:-apple-system,system-ui,sans-serif;background:#eef0f5;color:#0b0b0f;'
            .'display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:20px}'
            .'.c{background:#fff;border-radius:20px;padding:28px 26px;max-width:440px;box-shadow:0 10px 40px rgba(0,0,0,.1)}'
            .'h1{font-size:20px;margin:0 0 12px}p,div{font-size:15px;line-height:1.5}'
            .'a{color:#0a84ff}code{background:#f0f0f4;padding:2px 6px;border-radius:6px;font-size:13px}</style>'
            .'</head><body><div class="c"><h1>'.$title.'</h1><div>'.$body.'</div></div></body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=utf-8');
    }
}
