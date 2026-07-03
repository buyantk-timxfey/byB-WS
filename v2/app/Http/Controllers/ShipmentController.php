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

        $rows = Shipment::with(['counterparty:id,name', 'carrier:id,name', 'items', 'etaChanges'])
            ->orderByDesc('date')->orderByDesc('id')->get()
            ->map(function (Shipment $s) use ($paidByShipment, $matchesByShipment) {
                $total = $s->total();
                $paid = (float) ($paidByShipment[$s->id] ?? 0);
                // Задержка: от первого обещанного ETA до текущего (в днях)
                $firstEta = optional($s->etaChanges->sortBy('id')->first())->old_eta ?? $s->eta;
                $etaShift = ($firstEta && $s->eta) ? (int) $firstEta->copy()->startOfDay()->diffInDays($s->eta->copy()->startOfDay(), false) : 0;

                return [
                    'id' => $s->id,
                    'number' => $s->number,
                    'date' => optional($s->date)->toDateString(),
                    'supplier' => $s->counterparty?->name ?? '—',
                    'counterparty_id' => $s->counterparty_id,
                    'name' => $s->name,
                    'status' => $s->status,
                    'eta' => optional($s->eta)->toDateString(),
                    'eta_first' => optional($firstEta)->toDateString(),
                    'eta_shift' => $etaShift,
                    'eta_changes' => $s->etaChanges->sortByDesc('id')->values()->map(fn ($c) => [
                        'old' => optional($c->old_eta)->toDateString(),
                        'new' => optional($c->new_eta)->toDateString(),
                        'at' => optional($c->created_at)->format('d.m.Y'),
                    ]),
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
        try {
            DB::transaction(function () use ($shipment, $data) {
                // Перенос ETA фиксируется автоматически: дата уже была и меняется
                // на другую — пишем в историю (первичный ввод переносом не считается).
                $oldEta = optional($shipment->eta)->toDateString();
                $newEta = $data['eta'] ?? null;
                if ($oldEta && $newEta && $oldEta !== $newEta) {
                    $shipment->etaChanges()->create(['old_eta' => $oldEta, 'new_eta' => $newEta]);
                }
                // Перепроводим щадяще: уже проданный товар не блокирует правку —
                // потребление запоминается и списывается из новых партий заново.
                $consumed = $shipment->isPosted() ? ShipmentPosting::unpostPreservingSales($shipment) : [];
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
                    $fresh = $shipment->fresh()->load('items');
                    ShipmentPosting::post($fresh);
                    ShipmentPosting::reapplyConsumption($fresh, $consumed);
                } elseif ($consumed) {
                    throw new \RuntimeException('Товар из поставки уже продан — нельзя вернуть её в «Ожидает отправки».');
                }
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['shipment' => $e->getMessage()]);
        }

        return back();
    }

    public function destroy(Shipment $shipment)
    {
        try {
            if ($shipment->isPosted()) {
                ShipmentPosting::unpost($shipment);
            }
        } catch (\RuntimeException $e) {
            return back()->withErrors(['shipment' => $e->getMessage()]);
        }
        // Освободить привязанные оплаты, иначе операции выписки остаются
        // «разнесёнными» на несуществующий документ.
        $lineIds = BankMatch::where('target_type', 'shipment')->where('target_id', $shipment->id)->pluck('bank_line_id')->unique();
        BankMatch::where('target_type', 'shipment')->where('target_id', $shipment->id)->delete();
        $shipment->delete();
        foreach (BankLine::whereIn('id', $lineIds)->get() as $line) {
            BankReconcile::apply($line);
        }

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
        $line = BankLine::with('matches')->findOrFail($data['bank_line_id']);
        $amount = abs($data['amount']);

        // Нельзя разнести больше, чем осталось у операции — иначе «оплачено»
        // задваивается, а строка числится разнесённой на сумму больше себя самой.
        $remaining = round(abs((float) $line->amount) - $line->matchedSum(), 2);
        if ($amount > $remaining + 0.01) {
            return back()->withErrors(['amount' => 'У операции осталось только '.number_format($remaining, 2, ',', ' ').' ₽']);
        }

        // Повторная привязка той же операции к той же поставке — дополняем сумму,
        // а не создаём вторую строку-дубль.
        $existing = $line->matches->first(fn ($m) => $m->target_type === 'shipment' && $m->target_id === $shipment->id);
        if ($existing) {
            $existing->update(['amount' => $existing->amount + $amount]);
        } else {
            BankMatch::create([
                'bank_line_id' => $line->id,
                'target_type' => 'shipment',
                'target_id' => $shipment->id,
                'amount' => $amount,
            ]);
        }
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
