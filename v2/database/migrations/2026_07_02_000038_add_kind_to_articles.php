<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Статьи доходов: таблица expense_articles получает kind (expense|income) и хранит
// оба вида. Доходные статьи (кэшбэк, проценты банка) привязываются к приходным
// операциям выписки и попадают в P&L отдельной строкой «Прочие доходы»
// (turnover.type = income_other), не смешиваясь с выручкой от продаж.
return new class extends Migration
{
    public function up(): void
    {
        // target_type — enum: расширяем под income_article. На MySQL — ALTER, на
        // sqlite (локальные тесты) enum реализован CHECK-ограничением — пересоздаём колонку.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE bank_matches MODIFY target_type ENUM('sale','shipment','expense_article','income_article','acquiring','transfer','other') NOT NULL");
            DB::statement("ALTER TABLE turnover MODIFY type ENUM('income','income_other','cogs','acquiring','expense') NOT NULL");
        } else {
            Schema::table('bank_matches', function (Blueprint $t) {
                $t->string('target_type', 32)->change();
            });
            Schema::table('turnover', function (Blueprint $t) {
                $t->string('type', 24)->change();
            });
        }

        if (! Schema::hasColumn('expense_articles', 'kind')) {
            Schema::table('expense_articles', function (Blueprint $t) {
                $t->string('kind', 16)->default('expense')->after('name');
            });
        }

        // Стартовая доходная статья, чтобы раздел не был пустым
        $exists = DB::table('expense_articles')->where('kind', 'income')->exists();
        if (! $exists) {
            DB::table('expense_articles')->insert([
                'name' => 'Кэшбэк', 'kind' => 'income', 'is_system' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('expense_articles', 'kind')) {
            Schema::table('expense_articles', function (Blueprint $t) {
                $t->dropColumn('kind');
            });
        }
    }
};
