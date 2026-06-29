<?php

use App\Http\Controllers\FinanceController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReferenceController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\WarehouseController;
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

// Поставки — реальные данные
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/shipments', [ShipmentController::class, 'index'])->name('shipments');
    Route::post('/shipments', [ShipmentController::class, 'store']);
    Route::put('/shipments/{shipment}', [ShipmentController::class, 'update']);
    Route::delete('/shipments/{shipment}', [ShipmentController::class, 'destroy']);
});

// Продажи — реальные данные
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/sales', [SaleController::class, 'index'])->name('sales');
    Route::post('/sales', [SaleController::class, 'store']);
    Route::put('/sales/{sale}', [SaleController::class, 'update']);
    Route::delete('/sales/{sale}', [SaleController::class, 'destroy']);
    Route::post('/sales/{sale}/pay', [SaleController::class, 'pay']);
});

// Склад — реальные данные (чтение из регистров)
Route::get('/warehouse', [WarehouseController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('warehouse');

// Банк — свёрстанная страница
Route::get('/bank', fn () => Inertia::render('Bank'))
    ->middleware(['auth', 'verified'])->name('bank');

// Финансы — реальные данные (P&L из оборотов)
Route::get('/finances', [FinanceController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('finances');

// Справочники — реальные данные
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/references', [ReferenceController::class, 'index'])->name('references');
    Route::post('/references/{type}', [ReferenceController::class, 'store']);
    Route::put('/references/{type}/{id}', [ReferenceController::class, 'update']);
    Route::delete('/references/{type}/{id}', [ReferenceController::class, 'destroy']);

    // Настройки
    Route::get('/settings', [SettingController::class, 'index'])->name('settings');
    Route::put('/settings', [SettingController::class, 'update']);
    Route::post('/settings/rules', [SettingController::class, 'storeRule']);
    Route::delete('/settings/rules/{rule}', [SettingController::class, 'destroyRule']);
});

// Транспорт — свёрстанная страница
Route::get('/vehicle', fn () => Inertia::render('Transport'))
    ->middleware(['auth', 'verified'])->name('vehicle');

// Почта — свёрстанная страница
Route::get('/mail', fn () => Inertia::render('Mail'))
    ->middleware(['auth', 'verified'])->name('mail');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
