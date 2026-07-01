<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// НДС у товаров в поставках — справочно (не влияет на Финансы/P&L).
// Цена в поставке уже с НДС — сумма НДС выделяется из неё, но может быть
// отредактирована вручную.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_rates', function (Blueprint $t) {
            $t->id();
            $t->decimal('rate', 5, 2)->unique();   // процент, напр. 20.00
            $t->timestamps();
        });

        foreach ([5, 10, 22] as $rate) {
            DB::table('vat_rates')->insert([
                'rate' => $rate, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::table('shipment_items', function (Blueprint $t) {
            $t->decimal('vat_rate', 5, 2)->nullable()->after('price');    // NULL = без НДС
            $t->decimal('vat_amount', 12, 2)->nullable()->after('vat_rate');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_items', function (Blueprint $t) {
            $t->dropColumn(['vat_rate', 'vat_amount']);
        });
        Schema::dropIfExists('vat_rates');
    }
};
