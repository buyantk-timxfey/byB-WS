<?php

namespace App\Services\Posting;

use App\Models\Nomenclature;
use App\Models\Settlement;
use App\Models\Shipment;
use App\Models\StockBatch;
use App\Models\StockMove;
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
            throw new RuntimeException('Товар из этой поставки уже продан — удалить её нельзя.');
        }

        DB::transaction(function () use ($shipment) {
            StockBatch::whereIn('shipment_item_id', $shipment->items->pluck('id'))->delete();
            Fifo::release('shipment', $shipment->id);
            Settlement::where('doc_type', 'shipment')->where('doc_id', $shipment->id)->delete();
            $shipment->forceFill(['posted_at' => null])->save();
        });
    }

    // Распроведение для ПРАВКИ: продажи из партий не мешают — что уже продано,
    // запоминается и после перепроведения списывается из новых партий заново.
    // Возвращает карту [nomenclature_id => ['qty' => продано, 'moves' => [id движений продаж]]].
    public static function unpostPreservingSales(Shipment $shipment): array
    {
        if (! $shipment->isPosted()) {
            return [];
        }
        $shipment->load('items');
        $batchIds = StockBatch::whereIn('shipment_item_id', $shipment->items->pluck('id'))->pluck('id');
        $consumed = [];
        foreach (StockMove::whereIn('batch_id', $batchIds)->where('qty', '<', 0)->get() as $m) {
            $consumed[$m->nomenclature_id]['qty'] = ($consumed[$m->nomenclature_id]['qty'] ?? 0) + (float) -$m->qty;
            $consumed[$m->nomenclature_id]['moves'][] = $m->id;
        }

        DB::transaction(function () use ($shipment, $batchIds) {
            StockBatch::whereIn('id', $batchIds)->delete();   // batch_id у движений продаж занулится (FK)
            Fifo::release('shipment', $shipment->id);
            Settlement::where('doc_type', 'shipment')->where('doc_id', $shipment->id)->delete();
            $shipment->forceFill(['posted_at' => null])->save();
        });

        return $consumed;
    }

    // Повторно списать сохранённое потребление из новых партий поставки: движения
    // продаж перепривязываются к новым партиям, qty_left уменьшается. Себестоимость
    // в самих продажах не пересчитывается — она зафиксирована на момент продажи.
    public static function reapplyConsumption(Shipment $shipment, array $consumed): void
    {
        if (! $consumed) {
            return;
        }
        $shipment->load('items');
        foreach ($consumed as $nomId => $info) {
            $newQty = (float) $shipment->items->where('nomenclature_id', $nomId)->sum('qty');
            if ($newQty + 0.001 < $info['qty']) {
                $name = Nomenclature::find($nomId)?->name ?? ('товар #'.$nomId);
                $qty = rtrim(rtrim(number_format($info['qty'], 3, '.', ''), '0'), '.');
                throw new RuntimeException('По товару «'.$name.'» уже продано '.$qty.' — в поставке нельзя указать меньше.');
            }
        }

        DB::transaction(function () use ($shipment, $consumed) {
            foreach ($consumed as $nomId => $info) {
                $batches = StockBatch::whereIn('shipment_item_id', $shipment->items->pluck('id'))
                    ->where('nomenclature_id', $nomId)
                    ->orderBy('received_date')->orderBy('id')->get()->values();
                $bi = 0;
                foreach (StockMove::whereIn('id', $info['moves'])->orderBy('id')->get() as $move) {
                    $rest = (float) -$move->qty;
                    $first = true;
                    while ($rest > 0.0005 && $bi < $batches->count()) {
                        $b = $batches[$bi];
                        $avail = (float) $b->qty_left;
                        if ($avail <= 0.0005) {
                            $bi++;

                            continue;
                        }
                        $take = min($avail, $rest);
                        $b->decrement('qty_left', $take);
                        if ($first) {
                            $move->update(['batch_id' => $b->id, 'qty' => -$take]);
                            $first = false;
                        } else {
                            // Продажа распределилась по нескольким новым партиям — дописываем движение
                            StockMove::create([
                                'nomenclature_id' => $move->nomenclature_id, 'batch_id' => $b->id,
                                'date' => $move->date, 'qty' => -$take, 'unit_cost' => $move->unit_cost,
                                'doc_type' => $move->doc_type, 'doc_id' => $move->doc_id,
                            ]);
                        }
                        $rest -= $take;
                    }
                }
            }
        });
    }
}
