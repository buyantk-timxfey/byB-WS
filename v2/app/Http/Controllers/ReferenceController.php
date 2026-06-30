<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Carrier;
use App\Models\Counterparty;
use App\Models\ExpenseArticle;
use App\Models\Nomenclature;
use App\Models\NomenclatureGroup;
use App\Models\Settlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ReferenceController extends Controller
{
    // Карта типов справочника: модель + правила валидации
    private function map(): array
    {
        return [
            'counterparties' => [Counterparty::class, [
                'type' => 'required|in:Поставщик,Покупатель,Оба', 'name' => 'required|string|max:255',
                'inn' => 'nullable|string|max:20', 'kpp' => 'nullable|string|max:20',
                'bank_name' => 'nullable|string|max:255', 'bank_account' => 'nullable|string|max:40',
                'contact' => 'nullable|string|max:255', 'comment' => 'nullable|string',
            ]],
            'nomenclature' => [Nomenclature::class, [
                'name' => 'required|string|max:255', 'group_id' => 'nullable|exists:nomenclature_groups,id',
                'unit' => 'required|string|max:16', 'article' => 'nullable|string|max:64', 'comment' => 'nullable|string',
            ]],
            'carriers' => [Carrier::class, [
                'name' => 'required|string|max:255', 'site' => 'nullable|string|max:255', 'note' => 'nullable|string',
            ]],
            'accounts' => [Account::class, [
                'name' => 'required|string|max:255', 'type' => 'required|in:Банк,Касса',
                'bank' => 'nullable|string|max:255', 'last4' => 'nullable|string|max:8',
                'opening_balance' => 'nullable|numeric', 'color' => 'nullable|string|max:32', 'comment' => 'nullable|string',
            ]],
            'articles' => [ExpenseArticle::class, [
                'name' => 'required|string|max:255',
            ]],
            'groups' => [NomenclatureGroup::class, [
                'name' => 'required|string|max:255', 'parent_id' => 'nullable|exists:nomenclature_groups,id',
            ]],
        ];
    }

    public function index()
    {
        // Сальдо контрагентов из регистра взаиморасчётов
        $balances = Settlement::select('counterparty_id', DB::raw('SUM(amount) as bal'))
            ->groupBy('counterparty_id')->pluck('bal', 'counterparty_id');

        return Inertia::render('References', [
            'counterparties' => Counterparty::orderBy('name')->get()->map(fn ($c) => [
                'id' => $c->id, 'type' => $c->type, 'name' => $c->name, 'inn' => $c->inn,
                'kpp' => $c->kpp, 'bank_name' => $c->bank_name, 'bank_account' => $c->bank_account,
                'contact' => $c->contact, 'comment' => $c->comment,
                'debt' => (float) ($balances[$c->id] ?? 0),
            ]),
            'nomenclature' => Nomenclature::with('group:id,name')->orderBy('name')->get()->map(fn ($n) => [
                'id' => $n->id, 'name' => $n->name, 'group_id' => $n->group_id, 'group' => $n->group?->name,
                'unit' => $n->unit, 'article' => $n->article, 'comment' => $n->comment, 'qty' => $n->qty(),
            ]),
            'carriers' => Carrier::orderBy('name')->get(),
            'accounts' => Account::orderBy('name')->get()->map(fn ($a) => [
                'id' => $a->id, 'name' => $a->name, 'type' => $a->type, 'bank' => $a->bank,
                'last4' => $a->last4, 'opening_balance' => (float) $a->opening_balance,
                'color' => $a->color, 'comment' => $a->comment, 'balance' => $a->balance(),
            ]),
            'articles' => ExpenseArticle::orderBy('is_system', 'desc')->orderBy('name')->get(),
            'groups' => NomenclatureGroup::orderBy('name')->get(),
        ]);
    }

    public function store(Request $r, string $type)
    {
        [$model, $rules] = $this->resolve($type);
        $model::create($r->validate($rules));

        return back();
    }

    // Быстрое создание из других форм (возвращает JSON, без перезагрузки страницы)
    public function quick(Request $r, string $type)
    {
        if ($type === 'counterparties') {
            $d = $r->validate(['name' => 'required|string|max:255', 'type' => 'nullable|in:Поставщик,Покупатель,Оба']);
            $c = Counterparty::create(['name' => $d['name'], 'type' => $d['type'] ?? 'Поставщик']);

            return response()->json(['id' => $c->id, 'name' => $c->name]);
        }
        if ($type === 'nomenclature') {
            $d = $r->validate(['name' => 'required|string|max:255', 'unit' => 'required|string|max:16']);
            $n = Nomenclature::create($d);

            return response()->json(['id' => $n->id, 'name' => $n->name, 'unit' => $n->unit]);
        }
        abort(404);
    }

    public function update(Request $r, string $type, int $id)
    {
        [$model, $rules] = $this->resolve($type);
        $model::findOrFail($id)->update($r->validate($rules));

        return back();
    }

    public function destroy(string $type, int $id)
    {
        [$model] = $this->resolve($type);
        $row = $model::findOrFail($id);
        // системные статьи затрат удалять нельзя
        if ($type === 'articles' && $row->is_system) {
            return back()->withErrors(['name' => 'Системную статью удалить нельзя']);
        }
        $row->delete();

        return back();
    }

    private function resolve(string $type): array
    {
        abort_unless(isset($this->map()[$type]), 404);

        return $this->map()[$type];
    }
}
