<?php

namespace App\Services\Posting;

use App\Models\Adjustment;
use App\Models\StockBatch;
use App\Models\Turnover;
use App\Services\Fifo;
use Illuminate\Support\Facades\DB;

// Инвентаризация: выравнивание учётного остатка под фактический.
// Недостача (diff<0) → списание по FIFO + расход. Излишек (diff>0) → приход
// по последней известной себестоимости (или 0, если партий не было).
class AdjustmentPosting
{
    public static function post(Adjustment $adjustment): void
    {
        if ($adjustment->posted_at) {
            return;
        }
        $adjustment->load('items');

        DB::transaction(function () use ($adjustment) {
            $lossCost = 0.0;
            foreach ($adjustment->items as $item) {
                if (! $item->nomenclature_id) {
                    continue;
                }
                $diff = (float) $item->qty_fact - (float) $item->qty_book;
                if ($diff < 0) {
                    $lossCost += Fifo::consume($item->nomenclature_id, -$diff, $adjustment->date, 'adjustment', $adjustment->id);
                } elseif ($diff > 0) {
                    $lastCost = (float) (StockBatch::where('nomenclature_id', $item->nomenclature_id)
                        ->orderByDesc('received_date')->orderByDesc('id')->value('unit_cost') ?? 0);
                    Fifo::receive($item->nomenclature_id, null, $diff, $lastCost, $adjustment->date, $adjustment->id, 'adjustment');
                }
                $item->forceFill(['diff' => $diff])->save();
            }
            if ($lossCost > 0) {
                Turnover::create([
                    'date' => $adjustment->date, 'type' => 'expense', 'amount' => $lossCost,
                    'doc_type' => 'adjustment', 'doc_id' => $adjustment->id,
                ]);
            }
            $adjustment->forceFill(['posted_at' => now()])->save();
        });
    }
}
