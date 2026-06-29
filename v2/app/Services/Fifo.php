<?php

namespace App\Services;

use App\Models\Nomenclature;
use App\Models\StockBatch;
use App\Models\StockMove;
use Illuminate\Support\Carbon;

// FIFO-движок склада: списание и возврат партий + аудит движений.
class Fifo
{
    // Списать qty единиц номенклатуры по FIFO. Возвращает суммарную себестоимость.
    // Допускает уход в минус (продажа под заказ): остаток-нехватка пишется по нулевой
    // себестоимости и уточнится при ближайшем приходе.
    public static function consume(int $nomenclatureId, float $qty, Carbon|string $date, string $docType, int $docId): float
    {
        $remaining = $qty;
        $totalCost = 0.0;

        $batches = StockBatch::where('nomenclature_id', $nomenclatureId)
            ->where('qty_left', '>', 0)
            ->orderBy('received_date')->orderBy('id')
            ->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }
            $take = min($remaining, (float) $batch->qty_left);
            $batch->decrement('qty_left', $take);
            $cost = $take * (float) $batch->unit_cost;
            $totalCost += $cost;
            $remaining -= $take;

            StockMove::create([
                'nomenclature_id' => $nomenclatureId,
                'batch_id' => $batch->id,
                'date' => $date,
                'qty' => -$take,
                'unit_cost' => $batch->unit_cost,
                'doc_type' => $docType,
                'doc_id' => $docId,
            ]);
        }

        // Нехватка — отрицательный остаток (продажа под заказ), себестоимость 0 до прихода
        if ($remaining > 0) {
            StockMove::create([
                'nomenclature_id' => $nomenclatureId,
                'batch_id' => null,
                'date' => $date,
                'qty' => -$remaining,
                'unit_cost' => 0,
                'doc_type' => $docType,
                'doc_id' => $docId,
            ]);
        }

        return round($totalCost, 2);
    }

    // Вернуть на склад всё, что списывал документ (распроведение): обратно по партиям.
    public static function release(string $docType, int $docId): void
    {
        $moves = StockMove::where('doc_type', $docType)->where('doc_id', $docId)->get();
        foreach ($moves as $move) {
            if ($move->batch_id && $move->qty < 0) {
                StockBatch::where('id', $move->batch_id)->increment('qty_left', -$move->qty);
            }
            $move->delete();
        }
    }

    // Приход партии (оприходование поступления/излишка).
    public static function receive(int $nomenclatureId, ?int $shipmentItemId, float $qty, float $unitCost, Carbon|string $date, int $docId, string $docType = 'shipment'): StockBatch
    {
        $batch = StockBatch::create([
            'nomenclature_id' => $nomenclatureId,
            'shipment_item_id' => $shipmentItemId,
            'received_date' => $date,
            'qty_in' => $qty,
            'qty_left' => $qty,
            'unit_cost' => $unitCost,
        ]);

        StockMove::create([
            'nomenclature_id' => $nomenclatureId,
            'batch_id' => $batch->id,
            'date' => $date,
            'qty' => $qty,
            'unit_cost' => $unitCost,
            'doc_type' => $docType,
            'doc_id' => $docId,
        ]);

        return $batch;
    }
}
