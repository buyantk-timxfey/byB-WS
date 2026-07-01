<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

// Блокировка сессии после бездействия — не разлогинивает (сессия и авторизация
// остаются целы), просто требует PIN на /pin, чтобы продолжить работу.
class LockIdleSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $timeoutSeconds = ((int) (Setting::get('idle_lock_minutes') ?: 30)) * 60;
            $last = $request->session()->get('last_activity_at');

            if ($last && (time() - $last) > $timeoutSeconds) {
                $request->session()->put('locked', true);
            }

            if ($request->session()->get('locked') && ! $request->routeIs('pin', 'pin.attempt', 'logout')) {
                return redirect()->route('pin');
            }

            $request->session()->put('last_activity_at', time());
        }

        return $next($request);
    }
}
