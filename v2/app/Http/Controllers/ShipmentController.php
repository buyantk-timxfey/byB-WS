<?php

namespace App\Http\Controllers;

use App\Models\BankMatch;
use App\Models\Carrier;
use App\Models\Counterparty;
use App\Models\Nomenclature;
use App\Models\Shipment;
use App\Services\DocNumber;
use App\Services\Posting\ShipmentPosting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ShipmentController extends Controller
{
    public function index()
    {
        // Оплачено поставщику = сумма привязок строк выписки к поставке
        $paidByShipment = BankMatch::where('target_type', 'shipment')
            ->select('target_id', DB::raw('SUM(amount) as paid'))
            ->groupBy('target_id')->pluck('paid', 'target_id');

        $rows = Shipment::with(['counterparty:id,name', 'carrier:id,name', 'items'])
            ->orderByDesc('date')->orderByDesc('id')->get()
            ->map(function (Shipment $s) use ($paidByShipment) {
                $total = $s->total();
                $paid = (float) ($paidByShipment[$s->id] ?? 0);

                return [
                    'id' => $s->id,
                    'number' => $s->number,
                    'date' => optional($s->date)->toDateString(),
                    'supplier' => $s->counterparty?->name ?? '—',
                    'counterparty_id' => $s->counterparty_id,
                    'name' => $s->name,
                    'status' => $s->status,
                    'eta' => optional($s->eta)->toDateString(),
                    'carrier' => $s->carrier?->name,
                    'carrier_id' => $s->carrier_id,
                    'tracking' => $s->tracking,
                    'delivery' => (float) $s->delivery_cost,
                    'problem' => (bool) $s->problem,
                    'sum' => $total,
                    'paid' => $paid,
                    'posted' => $s->isPosted(),
                    'items' => $s->items->map(fn ($i) => [
                        'id' => $i->id, 'nomenclature_id' => $i->nomenclature_id,
                        'name' => $i->nomenclature?->name, 'qty' => (float) $i->qty, 'price' => (float) $i->price,
                    ]),
                ];
            });

        return Inertia::render('Shipments', [
            'rows' => $rows,
            'suppliers' => Counterparty::whereIn('type', ['Поставщик', 'Оба'])->orderBy('name')->get(['id', 'name']),
            'carriers' => Carrier::orderBy('name')->get(['id', 'name']),
            'goods' => Nomenclature::orderBy('name')->get(['id', 'name', 'unit']),
        ]);
    }

    public function store(Request $r)
    {
        $data = $this->validateData($r);
        DB::transaction(function () use ($data) {
            $shipment = Shipment::create([
                'number' => DocNumber::next('shipment', $data['date']),
                'date' => $data['date'],
                'counterparty_id' => $data['counterparty_id'] ?? null,
                'name' => $data['name'] ?? null,
                'status' => $data['status'],
                'eta' => $data['eta'] ?? null,
                'carrier_id' => $data['carrier_id'] ?? null,
                'tracking' => $data['tracking'] ?? null,
                'delivery_cost' => $data['delivery'] ?? 0,
                'problem' => $data['problem'] ?? false,
            ]);
            $this->syncItems($shipment, $data['items'] ?? []);
            // Оприходование при «В пути»/«Завершено»
            if ($data['status'] !== 'Ожидает отправки') {
                ShipmentPosting::post($shipment->fresh()->load('items'));
            }
        });

        return back();
    }

    public function update(Request $r, Shipment $shipment)
    {
        $data = $this->validateData($r);
        DB::transaction(function () use ($shipment, $data) {
            if ($shipment->isPosted()) {
                ShipmentPosting::unpost($shipment);   // перепроводим
            }
            $shipment->update([
                'date' => $data['date'],
                'counterparty_id' => $data['counterparty_id'] ?? null,
                'name' => $data['name'] ?? null,
                'status' => $data['status'],
                'eta' => $data['eta'] ?? null,
                'carrier_id' => $data['carrier_id'] ?? null,
                'tracking' => $data['tracking'] ?? null,
                'delivery_cost' => $data['delivery'] ?? 0,
                'problem' => $data['problem'] ?? false,
            ]);
            $this->syncItems($shipment, $data['items'] ?? []);
            if ($data['status'] !== 'Ожидает отправки') {
                ShipmentPosting::post($shipment->fresh()->load('items'));
            }
        });

        return back();
    }

    public function destroy(Shipment $shipment)
    {
        if ($shipment->isPosted()) {
            ShipmentPosting::unpost($shipment);
        }
        $shipment->delete();

        return back();
    }

    private function validateData(Request $r): array
    {
        return $r->validate([
            'date' => 'required|date',
            'counterparty_id' => 'nullable|exists:counterparties,id',
            'name' => 'nullable|string|max:255',
            'status' => 'required|in:Ожидает отправки,В пути,Завершено',
            'eta' => 'nullable|date',
            'carrier_id' => 'nullable|exists:carriers,id',
            'tracking' => 'nullable|string|max:64',
            'delivery' => 'nullable|numeric|min:0',
            'problem' => 'boolean',
            'items' => 'array',
            'items.*.name' => 'nullable|string|max:255',
            'items.*.qty' => 'required|numeric',
            'items.*.price' => 'required|numeric',
        ]);
    }

    // Позиции: товар вводится по наименованию. Точное совпадение — берём существующий,
    // иначе создаём новый (приходуется на склад при проведении).
    private function syncItems(Shipment $shipment, array $items): void
    {
        $shipment->items()->delete();
        foreach ($items as $i) {
            $name = trim($i['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $nom = \App\Models\Nomenclature::firstOrCreate(['name' => $name], ['unit' => 'шт']);
            $shipment->items()->create([
                'nomenclature_id' => $nom->id,
                'qty' => $i['qty'],
                'price' => $i['price'],
            ]);
        }
    }
}
