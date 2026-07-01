<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BankBatch;
use App\Models\BankLine;
use App\Models\BankMatch;
use App\Models\ExpenseArticle;
use App\Models\ReconRule;
use App\Models\Sale;
use App\Models\Shipment;
use App\Services\BankReconcile;
use App\Services\BankStatementParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class BankController extends Controller
{
    public function index()
    {
        $accounts = Account::orderBy('name')->get()->map(fn (Account $a) => [
            'id' => $a->id, 'name' => $a->name, 'bank' => $a->bank, 'last4' => $a->last4,
            'type' => $a->type, 'balance' => $a->balance(), 'color' => $a->color,
            'unmatched' => $a->lines()->whereIn('status', ['unmatched', 'partial'])->count(),
        ]);

        $lines = BankLine::with(['matches', 'account:id,name,last4'])
            ->orderByDesc('date')->orderByDesc('id')->limit(300)->get()
            ->map(fn (BankLine $l) => [
                'id' => $l->id, 'date' => optional($l->date)->toDateString(),
                'party' => $l->counterparty_name ?? $l->purpose ?? '—', 'purpose' => $l->purpose,
                'account' => trim(($l->account?->name ?? '')),
                'amount' => (float) $l->amount, 'status' => $l->status,
                'inn' => $l->inn,
                'link' => $this->matchLabel($l),
                'match' => $l->matches->first() ? [
                    'target_type' => $l->matches->first()->target_type,
                    'target_id'   => $l->matches->first()->target_id,
                ] : null,
            ]);

        return Inertia::render('Bank', [
            'accounts' => $accounts,
            'lines' => $lines,
            'articles' => ExpenseArticle::orderBy('name')->get(['id', 'name']),
            'openSales' => $this->openSales(),
            'openShipments' => $this->openShipments(),
            'importAccounts' => Account::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function import(Request $r)
    {
        $r->validate(['file' => 'required|file', 'account_id' => 'required|exists:accounts,id']);
        $raw = file_get_contents($r->file('file')->getRealPath());
        $parsed = BankStatementParser::parse($raw);

        $batch = BankBatch::create([
            'account_id' => $r->account_id,
            'period_start' => $parsed['header']['from'],
            'period_end' => $parsed['header']['to'],
            'opening_balance' => $parsed['header']['opening'],
            'closing_balance' => $parsed['header']['closing'],
            'imported_at' => now(),
        ]);

        $rules = ReconRule::orderBy('priority')->get();
        $created = 0;
        $turnIn = 0;
        $turnOut = 0;

        DB::transaction(function () use ($parsed, $r, $batch, $rules, &$created, &$turnIn, &$turnOut) {
            foreach ($parsed['docs'] as $d) {
                $existing = BankLine::where('dedup_hash', $d['dedup'])->first();
                if ($existing) {
                    // Дедуп повторного импорта — но подтягиваем контрагента/назначение,
                    // если раньше парсер их не распознал (например, старый формат без Плательщик1/Получатель1).
                    if (! $existing->counterparty_name && $d['counterparty_name']) {
                        $existing->update(['counterparty_name' => $d['counterparty_name'], 'inn' => $d['inn'] ?: $existing->inn]);
                    }
                    continue;
                }
                $line = BankLine::create([
                    'account_id' => $r->account_id, 'batch_id' => $batch->id,
                    'date' => $d['date'], 'amount' => $d['amount'],
                    'counterparty_name' => $d['counterparty_name'], 'inn' => $d['inn'],
                    'purpose' => $d['purpose'], 'doc_number' => $d['number'],
                    'dedup_hash' => $d['dedup'], 'status' => 'unmatched',
                ]);
                $created++;
                $d['amount'] >= 0 ? $turnIn += $d['amount'] : $turnOut += abs($d['amount']);
                $this->autoApply($line, $rules);
            }
            $batch->update(['lines_count' => $created, 'turn_in' => $turnIn, 'turn_out' => $turnOut]);
        });

        return back()->with('imported', $created);
    }

    // Авто-правила: безопасно разносим только статьи/эквайринг (по тексту назначения/ИНН)
    private function autoApply(BankLine $line, $rules): void
    {
        foreach ($rules as $rule) {
            $hay = $rule->match_field === 'inn' ? (string) $line->inn : (string) $line->purpose;
            if ($rule->match_value === '' || mb_stripos($hay, $rule->match_value) === false) {
                continue;
            }
            if (in_array($rule->action_type, ['expense_article', 'acquiring'], true) && $line->amount < 0) {
                BankMatch::create([
                    'bank_line_id' => $line->id,
                    'target_type' => $rule->action_type === 'acquiring' ? 'acquiring' : 'expense_article',
                    'target_id' => $rule->article_id,
                    'amount' => abs($line->amount),
                ]);
                BankReconcile::apply($line->fresh());

                return;
            }
        }
    }

    public function reconcile(Request $r, BankLine $line)
    {
        $data = $r->validate([
            'matches' => 'array',
            'matches.*.target_type' => 'required|in:sale,shipment,expense_article,acquiring,transfer,other',
            'matches.*.target_id' => 'nullable|integer',
            'matches.*.amount' => 'required|numeric',
        ]);
        DB::transaction(function () use ($line, $data) {
            $line->matches()->delete();
            foreach ($data['matches'] ?? [] as $m) {
                BankMatch::create([
                    'bank_line_id' => $line->id, 'target_type' => $m['target_type'],
                    'target_id' => $m['target_id'] ?? null, 'amount' => abs($m['amount']),
                ]);
            }
            $line->status = 'unmatched';
            BankReconcile::apply($line->fresh());
        });

        return back();
    }

    public function ignore(BankLine $line)
    {
        $line->matches()->delete();
        $line->update(['status' => 'ignore']);
        BankReconcile::apply($line->fresh());

        return back();
    }

    // Удалить операцию выписки вместе с её проводками (взаиморасчёты/обороты)
    public function destroyLine(BankLine $line)
    {
        DB::transaction(function () use ($line) {
            \App\Models\Settlement::where('doc_type', 'bank_line')->where('doc_id', $line->id)->delete();
            \App\Models\Turnover::where('doc_type', 'bank_line')->where('doc_id', $line->id)->delete();
            $line->matches()->delete();
            $line->delete();
        });

        return back();
    }

    private function matchLabel(BankLine $l): ?string
    {
        $m = $l->matches->first();
        if (! $m) {
            return null;
        }
        return match ($m->target_type) {
            'sale' => 'Продажа '.optional(Sale::find($m->target_id))->number,
            'shipment' => 'Поставка '.optional(Shipment::find($m->target_id))->number,
            'expense_article' => 'Статья: '.optional(ExpenseArticle::find($m->target_id))->name,
            'acquiring' => 'Эквайринг',
            'transfer' => 'Перевод',
            default => null,
        };
    }

    private function openSales(): array
    {
        $paid = BankMatch::where('target_type', 'sale')->select('target_id', DB::raw('SUM(amount) as p'))->groupBy('target_id')->pluck('p', 'target_id');

        return Sale::with('counterparty:id,name')->whereIn('status', ['Выставлен', 'Оплачен'])->orderByDesc('date')->get()
            ->map(fn (Sale $s) => ['id' => $s->id, 'number' => $s->number, 'party' => $s->counterparty?->name, 'sum' => $s->total(), 'debt' => $s->total() - (float) ($paid[$s->id] ?? 0), 'inn' => $s->counterparty?->inn])
            ->filter(fn ($s) => $s['debt'] > 0.01)->values()->all();
    }

    private function openShipments(): array
    {
        $paid = BankMatch::where('target_type', 'shipment')->select('target_id', DB::raw('SUM(amount) as p'))->groupBy('target_id')->pluck('p', 'target_id');

        return Shipment::with('counterparty:id,name')->whereNotNull('posted_at')->orderByDesc('date')->get()
            ->map(fn (Shipment $s) => ['id' => $s->id, 'number' => $s->number, 'party' => $s->counterparty?->name, 'sum' => $s->total(), 'debt' => $s->total() - (float) ($paid[$s->id] ?? 0), 'inn' => $s->counterparty?->inn])
            ->filter(fn ($s) => $s['debt'] > 0.01)->values()->all();
    }
}
