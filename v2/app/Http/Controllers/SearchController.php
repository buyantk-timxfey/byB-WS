<?php

namespace App\Http\Controllers;

use App\Models\Counterparty;
use App\Models\Nomenclature;
use App\Models\Sale;
use App\Models\Shipment;
use Illuminate\Http\Request;

// Глобальный поиск по контрагентам, товарам и документам.
class SearchController extends Controller
{
    public function query(Request $r)
    {
        $q = trim((string) $r->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['groups' => []]);
        }
        $like = '%'.$q.'%';
        $groups = [];

        $cps = Counterparty::where('name', 'like', $like)->orWhere('inn', 'like', $like)->limit(6)->get()
            ->map(fn ($c) => ['icon' => 'building', 't' => $c->name, 's' => $c->type.($c->inn ? ' · ИНН '.$c->inn : ''), 'url' => '/references']);
        if ($cps->count()) {
            $groups[] = ['title' => 'Контрагенты', 'items' => $cps];
        }

        $noms = Nomenclature::where('name', 'like', $like)->orWhere('article', 'like', $like)->limit(6)->get()
            ->map(fn ($n) => ['icon' => 'package', 't' => $n->name, 's' => trim(($n->article ?: '').' · '.$n->unit, ' ·'), 'url' => '/warehouse']);
        if ($noms->count()) {
            $groups[] = ['title' => 'Товары', 'items' => $noms];
        }

        $ships = Shipment::with('counterparty:id,name')->where('number', 'like', $like)->orWhere('name', 'like', $like)->limit(6)->get()
            ->map(fn ($s) => ['icon' => 'package', 't' => $s->number.($s->name ? ' · '.$s->name : ''), 's' => 'Поставка · '.($s->counterparty?->name ?? '—'), 'url' => '/shipments']);
        if ($ships->count()) {
            $groups[] = ['title' => 'Поставки', 'items' => $ships];
        }

        $sales = Sale::with('counterparty:id,name')->where('number', 'like', $like)->limit(6)->get()
            ->map(fn ($s) => ['icon' => 'cart', 't' => $s->number, 's' => 'Продажа · '.($s->counterparty?->name ?? '—'), 'url' => '/sales']);
        if ($sales->count()) {
            $groups[] = ['title' => 'Продажи', 'items' => $sales];
        }

        return response()->json(['groups' => $groups]);
    }
}
