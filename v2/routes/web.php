<?php

use App\Http\Controllers\Auth\PinController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\MailController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReferenceController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ShipmentController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\WarehouseController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Корень → дашборд (если не залогинен, middleware перекинет на /login)
Route::get('/', fn () => redirect()->route('dashboard'));

Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])->name('dashboard');

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

// Банк — реальные данные (импорт выписки + сверка)
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/bank', [BankController::class, 'index'])->name('bank');
    Route::post('/bank/import', [BankController::class, 'import']);
    Route::post('/bank/lines/{line}/reconcile', [BankController::class, 'reconcile']);
    Route::post('/bank/lines/{line}/ignore', [BankController::class, 'ignore']);
    Route::delete('/bank/lines/{line}', [BankController::class, 'destroyLine']);
});

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

// Транспорт — реальные данные
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/vehicle', [VehicleController::class, 'index'])->name('vehicle');
    Route::post('/vehicle/fuel', [VehicleController::class, 'storeFuel']);
    Route::post('/vehicle/trip', [VehicleController::class, 'storeTrip']);
    Route::post('/vehicle/wash', [VehicleController::class, 'storeWash']);
    Route::post('/vehicle/topup', [VehicleController::class, 'storeTopup']);
    Route::put('/vehicle/settings', [VehicleController::class, 'updateSettings']);
});

// Почта — реальные данные
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/mail', [MailController::class, 'index'])->name('mail');
    Route::post('/mail/accounts', [MailController::class, 'storeAccount']);
    Route::post('/mail/compose', [MailController::class, 'compose']);
    Route::post('/mail/{message}/read', [MailController::class, 'markRead']);
    Route::delete('/mail/{message}', [MailController::class, 'destroy']);
    Route::post('/mail/accounts/{account}/sync', [MailController::class, 'sync']);
});

// Одноразовый веб-установщик (создаёт БД с нуля без консоли)
Route::get('/setup', [\App\Http\Controllers\SetupController::class, 'run']);

// Быстрый вход по PIN
Route::get('/pin', [PinController::class, 'show'])->name('pin');
Route::post('/pin', [PinController::class, 'login']);

Route::middleware('auth')->group(function () {
    Route::put('/pin', [PinController::class, 'change']);
    Route::post('/deploy', [\App\Http\Controllers\DeployController::class, 'apply']);
    Route::get('/search', [\App\Http\Controllers\SearchController::class, 'query']);
    Route::get('/notifications', [\App\Http\Controllers\NotificationController::class, 'index']);
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
