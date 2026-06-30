<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BankLine;
use App\Models\BankMatch;
use App\Models\Counterparty;
use App\Models\ExpenseArticle;
use App\Models\Nomenclature;
use App\Models\Sale;
use App\Models\Setting;
use App\Services\BankReconcile;
use App\Services\DocNumber;
use App\Services\Posting\SalePosting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SaleController extends Controller
{
    public function index()
    {
        $paidBySale = BankMatch::where('target_type', 'sale')
            ->select('target_id', DB::raw('SUM(amount) as paid'))
            ->groupBy('target_id')->pluck('paid', 'target_id');

        $rows = Sale::with(['counterparty:id,name', 'items'])
            ->orderByDesc('date')->orderByDesc('id')->get()
            ->map(function (Sale $s) use ($paidBySale) {
                return [
                    'id' => $s->id,
                    'number' => $s->number,
                    'date' => optional($s->date)->toDateString(),
                    'buyer' => $s->counterparty?->name ?? $s->buyer_name ?? '—',
                    'counterparty_id' => $s->counterparty_id,
                    'account_id' => $s->account_id,
                    'sale_type' => $s->sale_type,
                    'payment_method' => $s->payment_method,
                    'status' => $s->status,
                    'sum' => $s->total(),
                    'cost' => $s->cost(),
                    'profit' => $s->profit(),
                    'paid' => (float) ($paidBySale[$s->id] ?? 0),
                    'posted' => $s->isPosted(),
                    'items' => $s->items->map(fn ($i) => [
                        'nomenclature_id' => $i->nomenclature_id, 'qty' => (float) $i->qty,
                        'price' => (float) $i->price, 'cost' => (float) $i->cost,
                    ]),
                ];
            });

        $defaultAccount = Account::where('name', 'Альфа-Банк')->value('id')
            ?? Account::orderBy('id')->value('id');

        return Inertia::render('Sales', [
            'rows' => $rows,
            'buyers' => Counterparty::whereIn('type', ['Покупатель', 'Оба'])->orderBy('name')->get(['id', 'name']),
            'goods' => Nomenclature::orderBy('name')->get(['id', 'name', 'unit']),
            'accounts' => Account::orderBy('name')->get(['id', 'name']),
            'defaultAccountId' => $defaultAccount,
            'rates' => [
                'card' => (float) Setting::get('acquiring_card_rate', 1.22),
                'sbp' => (float) Setting::get('acquiring_sbp_rate', 0.7),
            ],
        ]);
    }

    public function store(Request $r)
    {
        $data = $this->validateData($r);
        DB::transaction(function () use ($data) {
            $sale = Sale::create($this->attrs($data, DocNumber::next('sale', $data['date'])));
            $this->syncItems($sale, $data['items'] ?? []);
            SalePosting::sync($sale->fresh()->load('items'));
            $this->syncKassaPayment($sale->fresh());
        });

        return back();
    }

    public function update(Request $r, Sale $sale)
    {
        $data = $this->validateData($r);
        DB::transaction(function () use ($sale, $data) {
            $sale->update($this->attrs($data));
            $this->syncItems($sale, $data['items'] ?? []);
            SalePosting::sync($sale->fresh()->load('items'));
            $this->syncKassaPayment($sale->fresh());
        });

        return back();
    }

    public function destroy(Sale $sale)
    {
        DB::transaction(function () use ($sale) {
            BankLine::where('auto_sale_id', $sale->id)->delete();   // кассовый авто-приход
            SalePosting::unpost($sale);
            $sale->delete();
        });

        return back();
    }

    // «Оплачено сразу» — создать входящий платёж на остаток долга и разнести на продажу
    public function pay(Sale $sale)
    {
        $paid = (float) BankMatch::where('target_type', 'sale')->where('target_id', $sale->id)->sum('amount');
        $debt = $sale->total() - $paid;
        if ($debt <= 0) {
            return back();
        }
        $account = $sale->account_id ? Account::find($sale->account_id) : Account::first();
        if (! $account) {
            return back()->withErrors(['account' => 'Нет счёта зачисления']);
        }
        DB::transaction(function () use ($sale, $account, $debt) {
            $line = BankLine::create([
                'account_id' => $account->id,
                'date' => now()->toDateString(),
                'amount' => $debt,
                'counterparty_name' => $sale->counterparty?->name,
                'purpose' => 'Оплата по '.$sale->number.' (вручную)',
                'status' => 'matched',
            ]);
            BankMatch::create(['bank_line_id' => $line->id, 'target_type' => 'sale', 'target_id' => $sale->id, 'amount' => $debt]);
            BankReconcile::apply($line->fresh());
        });

        return back();
    }

    // Касса оплачивается на месте: авто-приход на счёт (выручка по сумме чека) и отдельной
    // строкой — комиссия эквайринга/СБП на статью «Эквайринг». Сопоставление остаётся.
    private function syncKassaPayment(Sale $sale): void
    {
        // Снять прежний авто-приход (идемпотентно при правках чека)
        BankLine::where('auto_sale_id', $sale->id)->delete();

        if ($sale->sale_type !== 'Касса' || $sale->status !== 'Оплачен') {
            return;
        }
        $gross = $sale->total();
        if ($gross <= 0) {
            return;
        }
        $account = $sale->account_id ? Account::find($sale->account_id) : null;
        $account ??= Account::where('name', 'Альфа-Банк')->first() ?? Account::first();
        if (! $account) {
            return;
        }
        $rate = $sale->payment_method === 'СБП'
            ? (float) Setting::get('acquiring_sbp_rate', 0.7)
            : (float) Setting::get('acquiring_card_rate', 1.22);
        $fee = round($gross * $rate / 100, 2);

        DB::transaction(function () use ($sale, $account, $gross, $fee) {
            // Приход = сумма чека (выручка)
            $income = BankLine::create([
                'account_id' => $account->id,
                'auto_sale_id' => $sale->id,
                'date' => $sale->date,
                'amount' => $gross,
                'counterparty_name' => 'Касса · '.($sale->payment_method ?? ''),
                'purpose' => 'Касса '.$sale->number,
                'status' => 'matched',
            ]);
            BankMatch::create(['bank_line_id' => $income->id, 'target_type' => 'sale', 'target_id' => $sale->id, 'amount' => $gross]);
            BankReconcile::apply($income->fresh());

            // Комиссия эквайринга — расход на статью «Эквайринг»
            if ($fee > 0) {
                $articleId = ExpenseArticle::where('name', 'Эквайринг')->value('id');
                $feeLine = BankLine::create([
                    'account_id' => $account->id,
                    'auto_sale_id' => $sale->id,
                    'date' => $sale->date,
                    'amount' => -$fee,
                    'counterparty_name' => 'Касса · комиссия',
                    'purpose' => 'Комиссия '.($sale->payment_method ?? '').' по '.$sale->number,
                    'status' => 'matched',
                ]);
                BankMatch::create(['bank_line_id' => $feeLine->id, 'target_type' => 'acquiring', 'target_id' => $articleId, 'amount' => $fee]);
                BankReconcile::apply($feeLine->fresh());
            }
        });
    }

    // Нормализация атрибутов: касса — без покупателя, всегда «Оплачен»; безнал — без способа оплаты.
    private function attrs(array $data, ?string $number = null): array
    {
        $isKassa = ($data['sale_type'] ?? 'Безналичная') === 'Касса';
        $attrs = [
            'date' => $data['date'],
            'sale_type' => $isKassa ? 'Касса' : 'Безналичная',
            'counterparty_id' => $isKassa ? null : ($data['counterparty_id'] ?? null),
            'account_id' => $data['account_id'] ?? null,
            'payment_method' => $isKassa ? ($data['payment_method'] ?? 'Карта') : null,
            'status' => $isKassa ? 'Оплачен' : $data['status'],
            'comment' => $data['comment'] ?? null,
        ];
        if ($number !== null) {
            $attrs['number'] = $number;
        }

        return $attrs;
    }

    private function validateData(Request $r): array
    {
        return $r->validate([
            'date' => 'required|date',
            'sale_type' => 'required|in:Касса,Безналичная',
            'counterparty_id' => 'nullable|exists:counterparties,id',
            'account_id' => 'nullable|exists:accounts,id',
            'payment_method' => 'nullable|in:Карта,СБП',
            'status' => 'required|in:Выставлен,Оплачен,Отменён',
            'comment' => 'nullable|string',
            'items' => 'array',
            'items.*.nomenclature_id' => 'nullable|exists:nomenclature,id',
            'items.*.qty' => 'required|numeric',
            'items.*.price' => 'required|numeric',
        ]);
    }

    private function syncItems(Sale $sale, array $items): void
    {
        $sale->items()->delete();
        foreach ($items as $i) {
            if (empty($i['nomenclature_id'])) {
                continue;
            }
            $sale->items()->create([
                'nomenclature_id' => $i['nomenclature_id'],
                'qty' => $i['qty'],
                'price' => $i['price'],
            ]);
        }
    }
}
