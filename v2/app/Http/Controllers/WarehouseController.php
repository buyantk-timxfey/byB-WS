<?php

namespace App\Http\Controllers;

use App\Models\Adjustment;
use App\Models\Nomenclature;
use App\Models\Setting;
use App\Models\StockBatch;
use App\Models\StockMove;
use App\Models\Writeoff;
use App\Services\DocNumber;
use App\Services\Fifo;
use App\Services\Posting\AdjustmentPosting;
use App\Services\Posting\WriteoffPosting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class WarehouseController extends Controller
{
    public function index()
    {
        $staleDays = (int) (Setting::get('stale_days', 60));

        // Остатки по номенклатуре из движений
        $qtyByNom = StockMove::select('nomenclature_id', DB::raw('SUM(qty) as q'))
            ->groupBy('nomenclature_id')->pluck('q', 'nomenclature_id');

        // Резерв: товары в выставленных счетах (ещё не оплачены/не списаны), даже если в пути
        $reservedByNom = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'Выставлен')
            ->select('sale_items.nomenclature_id', DB::raw('SUM(sale_items.qty) as q'))
            ->groupBy('sale_items.nomenclature_id')->pluck('q', 'sale_items.nomenclature_id');

        $batchesByNom = StockBatch::with('shipmentItem.shipment:id,number,name,status,counterparty_id', 'shipmentItem.shipment.counterparty:id,name')
            ->where('qty_left', '>', 0)->orderBy('received_date')->orderBy('id')
            ->get()->groupBy('nomenclature_id');

        $ids = array_unique(array_merge(array_keys($qtyByNom->toArray()), array_keys($reservedByNom->toArray())));
        $noms = Nomenclature::with('group:id,name')
            ->whereIn('id', $ids)
            ->orderBy('name')->get();

        $rows = $noms->map(function (Nomenclature $n) use ($qtyByNom, $reservedByNom, $batchesByNom, $staleDays) {
            $qty = (float) ($qtyByNom[$n->id] ?? 0);
            $reserved = (float) ($reservedByNom[$n->id] ?? 0);
            $batches = ($batchesByNom[$n->id] ?? collect())->map(function (StockBatch $b) {
                $item = $b->shipmentItem;
                $ship = $item?->shipment;
                $extra = $ship ? ($ship->name ?: $ship->counterparty?->name) : null;
                // Едущая часть партии: непринятый остаток позиции (частичная приёмка).
                // Считаем, что продажи в первую очередь съедают уже приехавшее.
                $transitQty = ($ship?->status === 'В пути' && $item)
                    ? max(0.0, min((float) $b->qty_left, (float) $item->qty - (float) $item->qty_received))
                    : 0.0;

                return [
                    // Номер + название поставки/поставщик — просто «№» ни о чём не говорил
                    'ship' => $ship ? $ship->number.($extra ? ' · '.$extra : '') : 'Приход без поставки',
                    'date' => optional($b->received_date)->toDateString(),
                    'qty' => (float) $b->qty_left,
                    'cost' => (float) $b->unit_cost,
                    'days' => $b->daysOnStock(),
                    'transit_qty' => $transitQty,
                    'transit' => $transitQty >= (float) $b->qty_left - 0.0005,   // едет целиком
                ];
            })->values();
            $value = $batches->sum(fn ($b) => $b['qty'] * $b['cost']);
            $transit = $batches->sum('transit_qty');
            $transitValue = $batches->sum(fn ($b) => $b['transit_qty'] * $b['cost']);
            $oldest = $batches->filter(fn ($b) => $b['qty'] - $b['transit_qty'] > 0.0005)->max('days') ?? 0;

            return [
                'id' => $n->id, 'name' => $n->name, 'group' => $n->group?->name ?? '—', 'unit' => $n->unit,
                // Остаток — только физический; едущее — отдельной колонкой
                'qty' => $qty - $transit, 'transit' => $transit,
                'reserved' => $reserved, 'available' => $qty - $reserved,
                'value' => round($value - $transitValue, 2), 'transit_value' => round($transitValue, 2),
                'days' => $oldest,
                'stale' => $oldest >= $staleDays, 'negative' => ($qty - $transit) < 0,
                'batches' => $batches,
            ];
        })->values();

        return Inertia::render('Warehouse', [
            'rows' => $rows,
            'staleDays' => $staleDays,
            'frozen' => round($rows->sum(fn ($r) => max(0, $r['value'])), 2),
            'transitMoney' => round($rows->sum('transit_value'), 2),
            'staleMoney' => round($rows->where('stale', true)->sum('value'), 2),
            'posCount' => $rows->where('qty', '>', 0)->count(),
            'negCount' => $rows->where('negative', true)->count(),
            'reservedCount' => $rows->where('reserved', '>', 0)->count(),
            'goods' => Nomenclature::orderBy('name')->get(['id', 'name', 'unit'])
                ->map(fn ($n) => ['id' => $n->id, 'name' => $n->name, 'unit' => $n->unit, 'qty' => (float) ($qtyByNom[$n->id] ?? 0)]),
        ]);
    }

    // ── Операции склада: оприходование / списание / инвентаризация ──

    private function bookQty(int $nomenclatureId): float
    {
        return (float) StockMove::where('nomenclature_id', $nomenclatureId)->sum('qty');
    }

    // Ручное оприходование без поставки (излишек, ввод начальных остатков).
    // Оформляется документом инвентаризации, партия — по указанной себестоимости.
    public function receive(Request $r)
    {
        $d = $r->validate([
            'nomenclature_id' => 'required|exists:nomenclature,id',
            'qty' => 'required|numeric|min:0.001',
            'unit_cost' => 'required|numeric|min:0',
        ]);
        DB::transaction(function () use ($d) {
            $book = $this->bookQty((int) $d['nomenclature_id']);
            $a = Adjustment::create([
                'number' => DocNumber::next('adjustment', now()),
                'date' => now()->toDateString(),
                'comment' => 'Ручное оприходование со склада',
            ]);
            $a->items()->create([
                'nomenclature_id' => $d['nomenclature_id'],
                'qty_book' => $book, 'qty_fact' => $book + (float) $d['qty'], 'diff' => $d['qty'],
            ]);
            Fifo::receive((int) $d['nomenclature_id'], null, (float) $d['qty'], (float) $d['unit_cost'], now()->toDateString(), $a->id, 'adjustment');
            $a->forceFill(['posted_at' => now()])->save();
        });

        return back();
    }

    // Списание брака/порчи: −остаток по FIFO, себестоимость — в расходы P&L
    public function writeoff(Request $r)
    {
        $d = $r->validate([
            'nomenclature_id' => 'required|exists:nomenclature,id',
            'qty' => 'required|numeric|min:0.001',
            'reason' => 'nullable|string|max:255',
        ]);
        $book = $this->bookQty((int) $d['nomenclature_id']);
        if ((float) $d['qty'] > $book + 0.0005) {
            return back()->withErrors(['warehouse' => 'На складе только '.rtrim(rtrim(number_format($book, 3, '.', ' '), '0'), '.').' — списать больше нельзя.']);
        }
        DB::transaction(function () use ($d) {
            $w = Writeoff::create([
                'number' => DocNumber::next('writeoff', now()),
                'date' => now()->toDateString(),
                'reason' => $d['reason'] ?? null,
            ]);
            $w->items()->create(['nomenclature_id' => $d['nomenclature_id'], 'qty' => $d['qty']]);
            WriteoffPosting::post($w->fresh());
        });

        return back();
    }

    // Инвентаризация: выравнивание учётного остатка под фактический
    public function adjust(Request $r)
    {
        $d = $r->validate([
            'nomenclature_id' => 'required|exists:nomenclature,id',
            'qty_fact' => 'required|numeric|min:0',
        ]);
        DB::transaction(function () use ($d) {
            $book = $this->bookQty((int) $d['nomenclature_id']);
            $a = Adjustment::create([
                'number' => DocNumber::next('adjustment', now()),
                'date' => now()->toDateString(),
                'comment' => 'Инвентаризация со склада',
            ]);
            $a->items()->create([
                'nomenclature_id' => $d['nomenclature_id'],
                'qty_book' => $book, 'qty_fact' => $d['qty_fact'],
            ]);
            AdjustmentPosting::post($a->fresh());
        });

        return back();
    }
}
