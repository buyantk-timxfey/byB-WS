<?php

namespace App\Http\Controllers;

use App\Models\Nomenclature;
use App\Models\Setting;
use App\Models\StockBatch;
use App\Models\StockMove;
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

        $batchesByNom = StockBatch::with('shipmentItem.shipment:id,number')
            ->where('qty_left', '>', 0)->orderBy('received_date')->orderBy('id')
            ->get()->groupBy('nomenclature_id');

        $noms = Nomenclature::with('group:id,name')
            ->whereIn('id', array_keys($qtyByNom->toArray()))
            ->orderBy('name')->get();

        $rows = $noms->map(function (Nomenclature $n) use ($qtyByNom, $batchesByNom, $staleDays) {
            $qty = (float) ($qtyByNom[$n->id] ?? 0);
            $batches = ($batchesByNom[$n->id] ?? collect())->map(fn (StockBatch $b) => [
                'ship' => $b->shipmentItem?->shipment?->number ?? '—',
                'date' => optional($b->received_date)->toDateString(),
                'qty' => (float) $b->qty_left,
                'cost' => (float) $b->unit_cost,
                'days' => $b->daysOnStock(),
            ])->values();
            $value = $batches->sum(fn ($b) => $b['qty'] * $b['cost']);
            $oldest = $batches->max('days') ?? 0;

            return [
                'id' => $n->id, 'name' => $n->name, 'group' => $n->group?->name ?? '—', 'unit' => $n->unit,
                'qty' => $qty, 'value' => round($value, 2), 'days' => $oldest,
                'stale' => $oldest >= $staleDays, 'negative' => $qty < 0,
                'batches' => $batches,
            ];
        })->values();

        return Inertia::render('Warehouse', [
            'rows' => $rows,
            'staleDays' => $staleDays,
            'frozen' => round($rows->sum(fn ($r) => max(0, $r['value'])), 2),
            'staleMoney' => round($rows->where('stale', true)->sum('value'), 2),
            'posCount' => $rows->where('qty', '>', 0)->count(),
            'negCount' => $rows->where('negative', true)->count(),
        ]);
    }
}
