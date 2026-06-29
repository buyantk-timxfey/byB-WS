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

// Поставки — свёрстанная страница
Route::get('/shipments', fn () => Inertia::render('Shipments'))
    ->middleware(['auth', 'verified'])->name('shipments');

// Продажи — свёрстанная страница
Route::get('/sales', fn () => Inertia::render('Sales'))
    ->middleware(['auth', 'verified'])->name('sales');

// Склад — свёрстанная страница
Route::get('/warehouse', fn () => Inertia::render('Warehouse'))
    ->middleware(['auth', 'verified'])->name('warehouse');

// Банк — свёрстанная страница
Route::get('/bank', fn () => Inertia::render('Bank'))
    ->middleware(['auth', 'verified'])->name('bank');

// Финансы — свёрстанная страница
Route::get('/finances', fn () => Inertia::render('Finances'))
    ->middleware(['auth', 'verified'])->name('finances');

// Справочники — свёрстанная страница
Route::get('/references', fn () => Inertia::render('References'))
    ->middleware(['auth', 'verified'])->name('references');

// Настройки — свёрстанная страница
Route::get('/settings', fn () => Inertia::render('Settings'))
    ->middleware(['auth', 'verified'])->name('settings');

// Транспорт — свёрстанная страница
Route::get('/vehicle', fn () => Inertia::render('Transport'))
    ->middleware(['auth', 'verified'])->name('vehicle');

// Разделы (пока заглушки — будут свёрстаны далее)
$sections = [
    'mail'        => 'Почта',
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
