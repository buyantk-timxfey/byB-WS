<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

// Быстрый вход по PIN (один аккаунт). Поверх обычного email/пароля.
class PinController extends Controller
{
    public function show(Request $request)
    {
        $locked = Auth::check() && $request->session()->get('locked');
        if (Auth::check() && ! $locked) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/Pin', ['locked' => $locked]);
    }

    public function login(Request $request)
    {
        $request->validate(['pin' => 'required|string']);
        $key = 'pin:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['pin' => 'Слишком много попыток. Подождите минуту.']);
        }

        $user = User::whereNotNull('pin_hash')->first();
        if (! $user || ! Hash::check($request->pin, $user->pin_hash)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['pin' => 'Неверный PIN']);
        }

        RateLimiter::clear($key);

        if (Auth::check() && Auth::id() === $user->id) {
            // Разблокировка уже активной сессии после бездействия — не логиним заново.
            $request->session()->put('locked', false);
        } else {
            Auth::login($user, true);
            $request->session()->regenerate();
        }
        $request->session()->put('last_activity_at', time());

        return redirect()->intended(route('dashboard'));
    }

    public function change(Request $request)
    {
        $request->validate(['pin' => 'required|string|min:4|max:8']);
        $user = $request->user();
        $user->pin_hash = Hash::make($request->pin);
        $user->save();

        return back();
    }
}
