<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Касса переводится на реальные операции из выписки (как безнал): виртуальные
// авто-приходы (bank_lines.auto_sale_id) больше не создаются, существующие —
// удаляются вместе с их проводками. «Хвост» комиссии эквайринга у карточных
// чеков закрывается полем sales.fee_writeoff (SaleStatusSync::settleKassaFee).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales', 'fee_writeoff')) {
            Schema::table('sales', function (Blueprint $t) {
                $t->decimal('fee_writeoff', 12, 2)->default(0)->after('status');
            });
        }

        $ids = DB::table('bank_lines')->whereNotNull('auto_sale_id')->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('settlements')->where('doc_type', 'bank_line')->whereIn('doc_id', $ids)->delete();
            DB::table('turnover')->where('doc_type', 'bank_line')->whereIn('doc_id', $ids)->delete();
            DB::table('bank_matches')->whereIn('bank_line_id', $ids)->delete();
            DB::table('bank_lines')->whereIn('id', $ids)->delete();
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales', 'fee_writeoff')) {
            Schema::table('sales', function (Blueprint $t) {
                $t->dropColumn('fee_writeoff');
            });
        }
    }
};
