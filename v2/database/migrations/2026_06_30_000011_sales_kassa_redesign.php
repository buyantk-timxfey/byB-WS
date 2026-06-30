<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Редизайн продаж: типы (Касса / Безналичная), новые статусы (Выставлен / Оплачен /
// Отменён), авто-привязка кассового прихода к строке выписки (bank_lines.auto_sale_id).
return new class extends Migration
{
    public function up(): void
    {
        // Тип продажи (на проде колонки ещё нет — добавляем; на свежей базе уже есть из create)
        if (! Schema::hasColumn('sales', 'sale_type')) {
            Schema::table('sales', function (Blueprint $t) {
                $t->string('sale_type', 24)->default('Безналичная')->after('account_id');
            });
        }
        if (! Schema::hasColumn('bank_lines', 'auto_sale_id')) {
            Schema::table('bank_lines', function (Blueprint $t) {
                $t->foreignId('auto_sale_id')->nullable()->after('account_id')
                    ->constrained('sales')->nullOnDelete();
            });
        }

        // MySQL: enum-колонку status привести к varchar, иначе UPDATE на новые значения упадёт
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE sales MODIFY status VARCHAR(32) NOT NULL DEFAULT 'Выставлен'");
        }

        // Ремап старых значений на новые
        DB::table('sales')->where('status', 'Отгружено')->update(['status' => 'Оплачен']);
        DB::table('sales')->where('status', 'Счёт')->update(['status' => 'Выставлен']);
        DB::table('sales')->where('status', 'Отменено')->update(['status' => 'Отменён']);

        // Способ оплаты: бывший «Эквайринг» = карта; «Без комиссии» у безнала очищаем
        DB::table('sales')->where('payment_method', 'Эквайринг')->update(['payment_method' => 'Карта']);
        DB::table('sales')->where('payment_method', 'Без комиссии')->update(['payment_method' => null]);

        // Существующие продажи — безналичные
        DB::table('sales')->whereNull('sale_type')->update(['sale_type' => 'Безналичная']);
    }

    public function down(): void
    {
        if (Schema::hasColumn('bank_lines', 'auto_sale_id')) {
            Schema::table('bank_lines', function (Blueprint $t) {
                $t->dropConstrainedForeignId('auto_sale_id');
            });
        }
        if (Schema::hasColumn('sales', 'sale_type')) {
            Schema::table('sales', function (Blueprint $t) {
                $t->dropColumn('sale_type');
            });
        }
    }
};
