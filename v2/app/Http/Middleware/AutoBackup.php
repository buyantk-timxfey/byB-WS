<?php

namespace App\Http\Middleware;

use App\Services\DbBackup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Ежедневный бэкап без крона: после отправки ответа (terminate) проверяем,
// не пора ли — пользователь задержки не почувствует.
class AutoBackup
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (auth()->check()) {
            DbBackup::runIfDue();
        }
    }
}
