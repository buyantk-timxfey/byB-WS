<?php

namespace App\Http\Controllers;

use App\Models\BankLine;
use App\Models\BankMatch;
use App\Models\Carrier;
use App\Models\Counterparty;
use App\Models\Nomenclature;
use App\Models\Shipment;
use App\Models\VatRate;
use App\Services\BankReconcile;
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
        $matchesByShipment = BankMatch::where('target_type', 'shipment')->with('line:id,date,counterparty_name,purpose')->get()->groupBy('target_id');
        $paidByShipment = $matchesByShipment->map(fn ($ms) => $ms->sum('amount'));

        $rows = Shipment::with(['counterparty:id,name', 'carrier:id,name', 'items'])
            ->orderByDesc('date')->orderByDesc('id')->get()
            ->map(function (Shipment $s) use ($paidByShipment, $matchesByShipment) {
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
                        'vat_rate' => $i->vat_rate !== null ? (float) $i->vat_rate : null,
                        'vat_amount' => $i->vat_amount !== null ? (float) $i->vat_amount : null,
                    ]),
                    'payments' => ($matchesByShipment[$s->id] ?? collect())->map(fn (BankMatch $m) => [
                        'match_id' => $m->id, 'bank_line_id' => $m->bank_line_id,
                        'date' => optional($m->line?->date)->toDateString(),
                        'party' => $m->line?->counterparty_name ?? $m->line?->purpose ?? '—',
                        'amount' => (float) $m->amount,
                    ])->values(),
                ];
            });

        return Inertia::render('Shipments', [
            'rows' => $rows,
            'suppliers' => Counterparty::whereIn('type', ['Поставщик', 'Оба'])->orderBy('name')->get(['id', 'name']),
            'carriers' => Carrier::orderBy('name')->get(['id', 'name']),
            'goods' => Nomenclature::orderBy('name')->get(['id', 'name', 'unit']),
            'vatRates' => VatRate::orderBy('rate')->get(['id', 'rate']),
            'bankCandidates' => $this->bankCandidates(),
        ]);
    }

    // Строки выписки, ещё не разнесённые полностью — кандидаты для привязки оплаты
    // прямо со стороны поставки (те же операции, что доступны в разнесении на странице Банк).
    private function bankCandidates(): array
    {
        return BankLine::whereIn('status', ['unmatched', 'partial'])
            ->where('amount', '<', 0)
            ->with('matches')
            ->orderByDesc('date')->get()
            ->map(fn (BankLine $l) => [
                'id' => $l->id,
                'date' => optional($l->date)->toDateString(),
                'party' => $l->counterparty_name ?? $l->purpose ?? '—',
                'purpose' => $l->purpose,
                'remaining' => round(abs((float) $l->amount) - $l->matchedSum(), 2),
            ])
            ->filter(fn ($l) => $l['remaining'] > 0.01)
            ->values()->all();
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

    // Привязка оплаты к поставке прямо со страницы Поставки — тот же BankMatch,
    // что создаётся при разнесении на странице Банк, просто с другой стороны.
    public function matchPayment(Request $r, Shipment $shipment)
    {
        $data = $r->validate([
            'bank_line_id' => 'required|exists:bank_lines,id',
            'amount' => 'required|numeric|min:0.01',
        ]);
        $line = BankLine::findOrFail($data['bank_line_id']);
        BankMatch::create([
            'bank_line_id' => $line->id,
            'target_type' => 'shipment',
            'target_id' => $shipment->id,
            'amount' => abs($data['amount']),
        ]);
        BankReconcile::apply($line->fresh());

        return back();
    }

    public function unmatchPayment(Shipment $shipment, BankMatch $match)
    {
        abort_unless($match->target_type === 'shipment' && $match->target_id === $shipment->id, 404);
        $line = $match->line;
        $match->delete();
        if ($line) {
            BankReconcile::apply($line->fresh());
        }

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
            'items.*.nomenclature_id' => 'nullable|exists:nomenclature,id',
            'items.*.qty' => 'required|numeric',
            'items.*.price' => 'required|numeric',
            'items.*.vat_rate' => 'nullable|numeric|min:0|max:100',
            'items.*.vat_amount' => 'nullable|numeric|min:0',
        ]);
    }

    // Товар выбирается из номенклатуры по id (SearchSelect на фронте) — без ввода по
    // тексту, иначе разные написания одного и того же товара плодят дубли в справочнике.
    private function syncItems(Shipment $shipment, array $items): void
    {
        $shipment->items()->delete();
        foreach ($items as $i) {
            if (empty($i['nomenclature_id'])) {
                continue;
            }
            $shipment->items()->create([
                'nomenclature_id' => $i['nomenclature_id'],
                'qty' => $i['qty'],
                'price' => $i['price'],
                'vat_rate' => $i['vat_rate'] ?? null,
                'vat_amount' => $i['vat_amount'] ?? null,
            ]);
        }
    }
}
