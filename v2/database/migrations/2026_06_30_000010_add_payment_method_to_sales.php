<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Способ оплаты продажи (касса): Эквайринг / СБП / Без комиссии — для подсказки комиссии.
// Плюс счёт-касса по умолчанию «Альфа-Банк», если его ещё нет в справочнике.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales', 'payment_method')) {
            Schema::table('sales', function (Blueprint $t) {
                $t->string('payment_method', 32)->nullable()->after('account_id');
            });
        }

        if (! DB::table('accounts')->where('name', 'Альфа-Банк')->exists()) {
            DB::table('accounts')->insert([
                'name' => 'Альфа-Банк',
                'type' => 'Банк',
                'bank' => 'Альфа-Банк',
                'opening_balance' => 0,
                'color' => '#ef3124',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales', 'payment_method')) {
            Schema::table('sales', function (Blueprint $t) {
                $t->dropColumn('payment_method');
            });
        }
    }
};
