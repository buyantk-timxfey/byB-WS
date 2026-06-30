<?php

namespace App\Services\Posting;

use App\Models\Sale;
use App\Models\Settlement;
use App\Models\Turnover;
use App\Services\Fifo;
use Illuminate\Support\Facades\DB;

// Проводка продажи по статусу (идемпотентна — сначала снимает прежние эффекты, затем
// применяет по текущему статусу):
//   Выставлен — резерв (расчётный, без движений склада) + дебиторка покупателя (безнал).
//   Оплачен   — списание склада по FIFO + себестоимость (COGS) + дебиторка (безнал).
//   Отменён   — ничего.
// Выручка (деньги) — НЕ здесь: кассовым методом при сопоставлении прихода (BankReconcile).
class SalePosting
{
    public static function sync(Sale $sale): void
    {
        $sale->load('items');

        DB::transaction(function () use ($sale) {
            // Снять прежние эффекты
            Fifo::release('sale', $sale->id);
            Settlement::where('doc_type', 'sale')->where('doc_id', $sale->id)->delete();
            Turnover::where('doc_type', 'sale')->where('doc_id', $sale->id)->delete();
            $sale->items()->update(['cost' => 0]);
            $sale->forceFill(['posted_at' => null])->save();

            if ($sale->status === 'Отменён') {
                return;
            }

            // Дебиторка покупателя (только безнал с контрагентом): он должен нам
            if ($sale->counterparty_id) {
                Settlement::create([
                    'counterparty_id' => $sale->counterparty_id,
                    'date' => $sale->date,
                    'amount' => $sale->total(),
                    'doc_type' => 'sale',
                    'doc_id' => $sale->id,
                    'comment' => 'Продажа '.$sale->number,
                ]);
            }

            // Списание склада и себестоимость — при «Оплачен» (товар ушёл покупателю)
            if ($sale->status === 'Оплачен') {
                $totalCost = 0.0;
                foreach ($sale->items as $item) {
                    if (! $item->nomenclature_id || $item->qty <= 0) {
                        continue;
                    }
                    $cost = Fifo::consume($item->nomenclature_id, (float) $item->qty, $sale->date, 'sale', $sale->id);
                    $item->forceFill(['cost' => $cost])->save();
                    $totalCost += $cost;
                }
                if ($totalCost > 0) {
                    Turnover::create([
                        'date' => $sale->date, 'type' => 'cogs', 'amount' => $totalCost,
                        'doc_type' => 'sale', 'doc_id' => $sale->id,
                    ]);
                }
                $sale->forceFill(['posted_at' => now()])->save();
            }
        });
    }

    // Полное снятие эффектов (при удалении продажи)
    public static function unpost(Sale $sale): void
    {
        DB::transaction(function () use ($sale) {
            Fifo::release('sale', $sale->id);
            Settlement::where('doc_type', 'sale')->where('doc_id', $sale->id)->delete();
            Turnover::where('doc_type', 'sale')->where('doc_id', $sale->id)->delete();
            $sale->items()->update(['cost' => 0]);
            $sale->forceFill(['posted_at' => null])->save();
        });
    }
}
