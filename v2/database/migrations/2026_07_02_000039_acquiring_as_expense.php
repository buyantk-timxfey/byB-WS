<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Эквайринг больше не отдельный тип в финансах: выручка кассы — полная сумма чека,
// комиссия карты — расход по статье «Эквайринг» (пара проводок sale_fee),
// реальные комиссии из выписки (СБП и т.п.) — обычный расход по той же статье.
return new class extends Migration
{
    public function up(): void
    {
        $articleId = DB::table('expense_articles')->where('name', 'Эквайринг')->value('id');
        if (! $articleId) {
            $articleId = DB::table('expense_articles')->insertGetId([
                'name' => 'Эквайринг', 'kind' => 'expense', 'is_system' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Старые обороты типа «acquiring» становятся расходом по статье «Эквайринг»
        DB::table('turnover')->where('type', 'acquiring')->whereNull('article_id')->update(['article_id' => $articleId]);
        DB::table('turnover')->where('type', 'acquiring')->update(['type' => 'expense']);

        // Бэкфилл: по уже закрытым карточным чекам (fee_writeoff > 0) доводим
        // выручку до полной суммы чека парой проводок доход/расход на комиссию.
        $sales = DB::table('sales')->where('fee_writeoff', '>', 0)->get(['id', 'date', 'fee_writeoff']);
        foreach ($sales as $s) {
            $exists = DB::table('turnover')->where('doc_type', 'sale_fee')->where('doc_id', $s->id)->exists();
            if ($exists) {
                continue;
            }
            DB::table('turnover')->insert([
                ['date' => $s->date, 'type' => 'income', 'article_id' => null, 'amount' => $s->fee_writeoff, 'doc_type' => 'sale_fee', 'doc_id' => $s->id, 'created_at' => now(), 'updated_at' => now()],
                ['date' => $s->date, 'type' => 'expense', 'article_id' => $articleId, 'amount' => $s->fee_writeoff, 'doc_type' => 'sale_fee', 'doc_id' => $s->id, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        DB::table('turnover')->where('doc_type', 'sale_fee')->delete();
    }
};
