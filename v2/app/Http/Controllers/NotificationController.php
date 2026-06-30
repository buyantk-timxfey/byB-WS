<?php

namespace App\Http\Controllers;

use App\Models\BankLine;
use App\Models\BankMatch;
use App\Models\Sale;
use App\Models\Shipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Уведомления из реальных событий: просроченные долги, истёкший ETA, неразнесённая выписка.
class NotificationController extends Controller
{
    public function index()
    {
        $now = Carbon::now();
        $money = fn ($n) => number_format(round($n), 0, '.', ' ').' ₽';
        $out = [];

        $paid = BankMatch::where('target_type', 'sale')
            ->select('target_id', DB::raw('SUM(amount) as p'))->groupBy('target_id')->pluck('p', 'target_id');
        foreach (Sale::with('counterparty')->where('status', 'Отгружено')->get() as $s) {
            $debt = $s->total() - (float) ($paid[$s->id] ?? 0);
            if ($debt > 0.01 && $s->date && Carbon::parse($s->date)->diffInDays($now) > 7) {
                $out[] = ['dot' => 'var(--expense)', 't' => 'Долг · '.($s->counterparty?->name ?? $s->number),
                    's' => Carbon::parse($s->date)->diffInDays($now).' дн · '.$money($debt), 'url' => '/sales'];
            }
        }
        foreach (Shipment::where('status', 'В пути')->whereNotNull('eta')->get() as $s) {
            if (Carbon::parse($s->eta)->isPast()) {
                $out[] = ['dot' => 'var(--warn)', 't' => 'ETA истёк · '.($s->name ?: $s->number),
                    's' => 'поставка просрочена', 'url' => '/shipments'];
            }
        }
        $un = BankLine::whereIn('status', ['unmatched', 'partial'])->count();
        if ($un) {
            $out[] = ['dot' => '#0a84ff', 't' => 'Новая выписка', 's' => $un.' строк не разнесено', 'url' => '/bank'];
        }

        // Контрагенты без ИНН (например, быстро созданные) — просьба дозаполнить
        $incomplete = \App\Models\Counterparty::where(function ($q) {
            $q->whereNull('inn')->orWhere('inn', '');
        })->orderByDesc('id')->limit(5)->get();
        foreach ($incomplete as $c) {
            $out[] = ['dot' => 'var(--warn)', 't' => 'Заполните контрагента', 's' => $c->name.' — нет ИНН', 'url' => '/references'];
        }

        return response()->json(['items' => $out, 'count' => count($out)]);
    }
}
