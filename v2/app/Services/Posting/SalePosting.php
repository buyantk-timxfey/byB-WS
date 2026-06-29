<?php

namespace App\Services\Posting;

use App\Models\Sale;
use App\Models\Settlement;
use App\Models\Turnover;
use App\Services\Fifo;
use Illuminate\Support\Facades\DB;

// Проведение продажи (Отгружено): списание склада по FIFO, дебиторка покупателя,
// признание себестоимости (COGS). Выручка — НЕ здесь: признаётся кассовым методом
// при сопоставлении прихода с продажей (см. BankReconcile).
class SalePosting
{
    public static function post(Sale $sale): void
    {
        if ($sale->isPosted()) {
            return;
        }
        $sale->load('items');

        DB::transaction(function () use ($sale) {
            $totalCost = 0.0;
            foreach ($sale->items as $item) {
                if (! $item->nomenclature_id || $item->qty <= 0) {
                    continue;
                }
                $cost = Fifo::consume($item->nomenclature_id, (float) $item->qty, $sale->date, 'sale', $sale->id);
                $item->forceFill(['cost' => $cost])->save();
                $totalCost += $cost;
            }

            // Дебиторка: покупатель должен нам (amount > 0)
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

            // Себестоимость проданного (COGS) — признаётся при отгрузке
            if ($totalCost > 0) {
                Turnover::create([
                    'date' => $sale->date,
                    'type' => 'cogs',
                    'amount' => $totalCost,
                    'doc_type' => 'sale',
                    'doc_id' => $sale->id,
                ]);
            }

            $sale->forceFill(['posted_at' => now()])->save();
        });
    }

    public static function unpost(Sale $sale): void
    {
        if (! $sale->isPosted()) {
            return;
        }
        DB::transaction(function () use ($sale) {
            Fifo::release('sale', $sale->id);                 // вернуть товар в партии
            Settlement::where('doc_type', 'sale')->where('doc_id', $sale->id)->delete();
            Turnover::where('doc_type', 'sale')->where('doc_id', $sale->id)->delete();
            $sale->items()->update(['cost' => 0]);
            $sale->forceFill(['posted_at' => null])->save();
        });
    }
}
