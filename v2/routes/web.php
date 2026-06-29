<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

// Поступления — свёрстанная страница
Route::get('/shipments', fn () => Inertia::render('Shipments'))
    ->middleware(['auth', 'verified'])->name('shipments');

// Разделы (пока заглушки — будут свёрстаны далее)
$sections = [
    'sales'       => 'Продажи',
    'warehouse'   => 'Склад',
    'bank'        => 'Банк',
    'finances'    => 'Финансы',
    'mail'        => 'Почта',
    'vehicle'     => 'Транспорт',
    'references'  => 'Справочники',
    'settings'    => 'Настройки',
];
foreach ($sections as $slug => $title) {
    Route::get("/{$slug}", fn () => Inertia::render('Stub', ['title' => $title]))
        ->middleware(['auth', 'verified'])->name($slug);
}

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
