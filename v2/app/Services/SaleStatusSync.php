<?php

namespace App\Services;

use App\Models\BankMatch;
use App\Models\Sale;
use App\Services\Posting\SalePosting;

// Статус безналичной продажи следует за фактической оплатой из выписки:
// привязано на всю сумму → «Оплачен», отвязали/не хватает → «Выставлен».
// Вызывается из всех мест, где меняются привязки (Банк, Поставки, Продажи).
// Касса и «Отменён» не трогаются: касса всегда оплачена на месте, отмену
// пользователь ставит осознанно.
class SaleStatusSync
{
    public static function recalc(?Sale $sale): void
    {
        if (! $sale || $sale->sale_type !== 'Безналичная' || $sale->status === 'Отменён') {
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

    public static function recalcById(?int $saleId): void
    {
        if ($saleId) {
            self::recalc(Sale::find($saleId));
        }
    }
}
