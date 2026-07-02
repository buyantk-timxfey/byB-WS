<?php

namespace App\Services;

use App\Models\BankMatch;
use App\Models\Sale;
use App\Services\Posting\SalePosting;

// Статус безналичной продажи следует за фактической оплатой из выписки:
// привязано на всю сумму → «Оплачен», отвязали/не хватает → «Выставлен».
// Вызывается из всех мест, где меняются привязки (Банк, Поставки, Продажи).
// Статус кассы не меняется (чек всегда оплачен на месте), но при привязке
// реального зачисления закрывается «хвост» комиссии эквайринга — см.
// settleKassaFee. «Отменён» не трогается: его пользователь ставит осознанно.
class SaleStatusSync
{
    public static function recalc(?Sale $sale): void
    {
        if (! $sale || $sale->status === 'Отменён') {
            return;
        }
        if ($sale->sale_type === 'Касса') {
            self::settleKassaFee($sale);

            return;
        }
        if ($sale->sale_type !== 'Безналичная') {
            return;
        }
        $paid = (float) BankMatch::where('target_type', 'sale')->where('target_id', $sale->id)->sum('amount');
        $total = $sale->total();
        $should = ($total > 0 && $paid >= $total - 0.01) ? 'Оплачен' : 'Выставлен';
        if ($sale->status !== $should) {
            $sale->status = $should;
            $sale->save();
            SalePosting::sync($sale->fresh()->load('items'));
        }
    }

    // Карта зачисляется за вычетом комиссии эквайринга, поэтому после привязки
    // реального прихода у чека остаётся «хвост» в размере комиссии. Остаток в
    // пределах 3.5% от суммы чека списываем в fee_writeoff — чек считается
    // закрытым и уходит из кандидатов. Отвязали оплату — списание снимается.
    private static function settleKassaFee(Sale $sale): void
    {
        $paid = (float) BankMatch::where('target_type', 'sale')->where('target_id', $sale->id)->sum('amount');
        $total = $sale->total();
        $debt = round($total - $paid, 2);
        $writeoff = ($paid > 0 && $debt > 0 && $debt <= round($total * 0.035, 2)) ? $debt : 0.0;
        if (abs((float) $sale->fee_writeoff - $writeoff) > 0.001) {
            $sale->fee_writeoff = $writeoff;
            $sale->save();
        }
        self::syncFeeTurnovers($sale->fresh());
    }

    // Выручка кассы в финансах — полная сумма чека: приход из выписки даёт только
    // net-часть, а комиссия карты доводится парой проводок (doc_type = sale_fee):
    // доход на сумму комиссии + расход по статье «Эквайринг». Итоговая прибыль
    // не меняется, но выручка и расход эквайринга видны честно. Идемпотентно.
    private static function syncFeeTurnovers(Sale $sale): void
    {
        \App\Models\Turnover::where('doc_type', 'sale_fee')->where('doc_id', $sale->id)->delete();
        $fee = (float) $sale->fee_writeoff;
        if ($fee <= 0) {
            return;
        }
        $articleId = \App\Models\ExpenseArticle::firstOrCreate(
            ['name' => 'Эквайринг'],
            ['kind' => 'expense', 'is_system' => true],
        )->id;
        \App\Models\Turnover::create(['date' => $sale->date, 'type' => 'income', 'amount' => $fee, 'doc_type' => 'sale_fee', 'doc_id' => $sale->id]);
        \App\Models\Turnover::create(['date' => $sale->date, 'type' => 'expense', 'article_id' => $articleId, 'amount' => $fee, 'doc_type' => 'sale_fee', 'doc_id' => $sale->id]);
    }

    public static function recalcById(?int $saleId): void
    {
        if ($saleId) {
            self::recalc(Sale::find($saleId));
        }
    }
}
