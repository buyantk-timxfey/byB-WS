<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Порядок карточек счетов по умолчанию: Альфа-Банк, затем Точка, затем Озон Банк —
// остальные счета (текущие и будущие) идут следом в исходном порядке.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(99)->after('color');
        });

        // utf8mb4_unicode_ci (коллация проекта) регистронезависима и для кириллицы,
        // поэтому обычный LIKE ловит любой регистр написания названия банка.
        $order = ['альфа' => 0, 'точка' => 1, 'озон' => 2];
        foreach ($order as $keyword => $position) {
            DB::table('accounts')
                ->where(function ($q) use ($keyword) {
                    $q->where('bank', 'like', "%{$keyword}%")->orWhere('name', 'like', "%{$keyword}%");
                })
                ->update(['sort_order' => $position]);
        }
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
