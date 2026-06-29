<?php

namespace App\Services\Posting;

use App\Models\Settlement;
use App\Models\Shipment;
use App\Models\StockBatch;
use App\Services\Fifo;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// Проведение поставки: оприходование партий (FIFO-источник) + кредиторка поставщику.
// Доставка распределяется в себестоимость пропорционально стоимости позиций.
class ShipmentPosting
{
    public static function post(Shipment $shipment): void
    {
        if ($shipment->isPosted()) {
            return;
        }
        $shipment->load('items');
        $goodsTotal = $shipment->goodsTotal();
        $delivery = (float) $shipment->delivery_cost;

        DB::transaction(function () use ($shipment, $goodsTotal, $delivery) {
            foreach ($shipment->items as $item) {
                if (! $item->nomenclature_id || $item->qty <= 0) {
                    continue;
                }
                $itemValue = (float) $item->qty * (float) $item->price;
                // доля доставки на эту позицию
                $deliveryShare = $goodsTotal > 0 ? $delivery * ($itemValue / $goodsTotal) : 0;
                $unitCost = round((float) $item->price + ($item->qty > 0 ? $deliveryShare / $item->qty : 0), 2);

                Fifo::receive($item->nomenclature_id, $item->id, (float) $item->qty, $unitCost, $shipment->date, $shipment->id);
            }

            // Кредиторка: мы должны поставщику (amount < 0)
            if ($shipment->counterparty_id) {
                Settlement::create([
                    'counterparty_id' => $shipment->counterparty_id,
                    'date' => $shipment->date,
                    'amount' => -$shipment->total(),
                    'doc_type' => 'shipment',
                    'doc_id' => $shipment->id,
                    'comment' => 'Поставка '.$shipment->number,
                ]);
            }

            $shipment->forceFill(['posted_at' => now()])->save();
        });
    }

    public static function unpost(Shipment $shipment): void
    {
        if (! $shipment->isPosted()) {
            return;
        }
        // Нельзя распровести, если из партий уже что-то продано
        $consumed = StockBatch::where('shipment_item_id', '!=', null)
            ->whereIn('shipment_item_id', $shipment->items->pluck('id'))
            ->whereColumn('qty_left', '<', 'qty_in')->exists();
        if ($consumed) {
            throw new RuntimeException('Нельзя распровести: товар из партий уже частично продан.');
        }

        DB::transaction(function () use ($shipment) {
            StockBatch::whereIn('shipment_item_id', $shipment->items->pluck('id'))->delete();
            Fifo::release('shipment', $shipment->id);
            Settlement::where('doc_type', 'shipment')->where('doc_id', $shipment->id)->delete();
            $shipment->forceFill(['posted_at' => null])->save();
        });
    }
}
