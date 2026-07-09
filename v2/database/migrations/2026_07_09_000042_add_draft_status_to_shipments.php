<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Статус поставки «Черновик» — «голая» поставка-напоминание (что нужно заказать).
// shipments.status — enum; расширяем. На MySQL ALTER MODIFY, на sqlite (локальные
// тесты) enum реализован CHECK-ограничением — переводим в обычный varchar.
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE shipments MODIFY status ENUM('Черновик','Ожидает отправки','В пути','Завершено') NOT NULL DEFAULT 'Ожидает отправки'");
        } else {
            Schema::table('shipments', function (Blueprint $t) {
                $t->string('status', 32)->default('Ожидает отправки')->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE shipments MODIFY status ENUM('Ожидает отправки','В пути','Завершено') NOT NULL DEFAULT 'Ожидает отправки'");
        }
    }
};
