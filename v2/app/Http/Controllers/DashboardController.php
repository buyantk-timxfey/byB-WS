<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\BankLine;
use App\Models\MailAccount;
use App\Models\Nomenclature;
use App\Models\Note;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\Shipment;
use App\Models\StockBatch;
use App\Models\StockMove;
use App\Models\Turnover;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $now = Carbon::now();
        $from = $now->copy()->startOfMonth()->toDateString();
        $to = $now->copy()->endOfMonth()->toDateString();
        $staleDays = (int) Setting::get('stale_days', 60);

        $sum = fn ($t) => (float) Turnover::where('type', $t)->whereBetween('date', [$from, $to])->sum('amount');
        $revenue = $sum('income');
        $cogs = $sum('cogs');
        $acq = $sum('acquiring');
        $exp = $sum('expense');
        $gross = $revenue - $cogs - $acq - $exp;
        $purchases = (float) Shipment::whereNotNull('posted_at')->whereBetween('date', [$from, $to])->get()->sum(fn ($s) => $s->total());

        // спарклайны: 8 мес
        $spark = fn (callable $f) => collect(range(7, 0))->map(function ($i) use ($now, $f) {
            $m = $now->copy()->subMonths($i);
            return $f($m->copy()->startOfMonth()->toDateString(), $m->copy()->endOfMonth()->toDateString());
        })->all();
        $incSpark = $spark(fn ($a, $b) => round((float) Turnover::where('type', 'income')->whereBetween('date', [$a, $b])->sum('amount') / 1000));
        $profSpark = $spark(fn ($a, $b) => round((
            (float) Turnover::where('type', 'income')->whereBetween('date', [$a, $b])->sum('amount')
            - (float) Turnover::whereIn('type', ['cogs', 'acquiring', 'expense'])->whereBetween('date', [$a, $b])->sum('amount')) / 1000));

        // Прошлый месяц — для динамики KPI
        $pm = $now->copy()->subMonthNoOverflow();
        $pa = $pm->copy()->startOfMonth()->toDateString();
        $pb = $pm->copy()->endOfMonth()->toDateString();
        $sumP = fn ($t) => (float) Turnover::where('type', $t)->whereBetween('date', [$pa, $pb])->sum('amount');
        $revP = $sumP('income');
        $grossP = $revP - $sumP('cogs') - $sumP('acquiring') - $sumP('expense');
        $purchP = (float) Shipment::whereNotNull('posted_at')->whereBetween('date', [$pa, $pb])->get()->sum(fn ($s) => $s->total());
        $marginP = $revP > 0 ? $grossP / $revP * 100 : 0;
        $margin = $revenue > 0 ? $gross / $revenue * 100 : 0;

        // дельта в % (для сумм) и в пп (для процентных метрик)
        $dPct = function (float $cur, float $prev): array {
            if ($prev <= 0) {
                return ['', false];
            }
            $d = round(($cur - $prev) / $prev * 100);
            return [($d >= 0 ? '+' : '−').abs($d).'%', $d < 0];
        };
        $dPp = function (float $cur, float $prev): array {
            $d = round($cur - $prev, 1);
            if (abs($d) < 0.05) {
                return ['', false];
            }
            return [($d >= 0 ? '+' : '−').number_format(abs($d), 1, ',', '').' пп', $d < 0];
        };
        [$revD, $revDown] = $dPct($revenue, $revP);
        [$grD, $grDown] = $dPct($gross, $grossP);
        [$mD, $mDown] = $dPp($margin, $marginP);
        [$pD, $pDownRaw] = $dPct($purchases, $purchP);

        $kpis = [
            ['label' => 'Выручка', 'value' => $this->m($revenue), 'delta' => $revD, 'down' => $revDown, 'sub' => 'пред. мес: '.$this->m($revP), 'spark' => $incSpark, 'color' => 'var(--income)'],
            ['label' => 'Прибыль', 'value' => $this->m($gross), 'delta' => $grD, 'down' => $grDown, 'sub' => 'пред. мес: '.$this->m($grossP), 'spark' => $profSpark, 'color' => 'var(--income)'],
            ['label' => 'Маржа', 'value' => round($margin, 1).'%', 'delta' => $mD, 'down' => $mDown, 'sub' => 'пред. мес: '.round($marginP, 1).'%', 'spark' => $profSpark, 'color' => 'var(--income)'],
            ['label' => 'ROI', 'value' => (($cogs + $acq + $exp) > 0 ? round($gross / ($cogs + $acq + $exp) * 100) : 0).'%', 'delta' => '', 'down' => false, 'sub' => 'прибыль / затраты', 'spark' => $profSpark, 'color' => 'var(--income)'],
            ['label' => 'Закупки', 'value' => $this->m($purchases), 'delta' => $pD, 'down' => true, 'sub' => 'пред. мес: '.$this->m($purchP), 'spark' => $incSpark, 'color' => 'var(--expense)'],
        ];

        $accounts = Account::orderBy('sort_order')->orderBy('name')->get()->map(fn ($a) => ['name' => $a->name, 'balance' => $a->balance()]);
        $unrecLines = BankLine::whereIn('status', ['unmatched', 'partial'])->get();
        // Приход/расход за месяц (по строкам выписки)
        $monthIn = (float) BankLine::whereBetween('date', [$from, $to])->where('amount', '>', 0)->sum('amount');
        $monthOut = abs((float) BankLine::whereBetween('date', [$from, $to])->where('amount', '<', 0)->sum('amount'));

        // Трекер поставок (кроме завершённых)
        $shipments = Shipment::with('counterparty:id,name')->where('status', '!=', 'Завершено')
            ->orderBy('eta')->limit(10)->get()->map(function (Shipment $s) {
                $start = $s->date ? Carbon::parse($s->date) : null;
                $eta = $s->eta ? Carbon::parse($s->eta) : null;
                $overdue = $eta && $eta->isPast();
                $wait = $s->status === 'Ожидает отправки';
                $pct = 0;
                if ($start && $eta && $eta->gt($start)) {
                    $pct = (int) min(100, max(0, round(Carbon::now()->diffInDays($start, false) * -1 / $start->diffInDays($eta) * 100)));
                }
                $color = $overdue ? 'var(--expense)' : ($wait ? 'var(--ink-3)' : ($pct >= 50 ? 'var(--income)' : 'var(--warn)'));
                $kind = $overdue ? 'overdue' : ($wait ? 'wait' : 'pct');

                return [
                    'cp' => $s->counterparty?->name ?? '—', 'name' => $s->name ?? $s->number,
                    'kind' => $kind, 'pct' => $pct, 'color' => $color,
                    'dates' => ($start ? $start->format('d.m') : '—').' → '.($eta ? $eta->format('d.m') : '—'),
                ];
            });

        // Склад-сигналы
        $batches = StockBatch::where('qty_left', '>', 0)->with('nomenclature:id,name')->get();
        $frozen = $batches->sum(fn ($b) => $b->qty_left * $b->unit_cost);
        $positions = StockMove::select('nomenclature_id')->groupBy('nomenclature_id')
            ->havingRaw('SUM(qty) > 0')->get()->count();
        $stale = $batches->filter(fn ($b) => $b->daysOnStock() >= $staleDays)
            ->groupBy('nomenclature_id')->map(fn ($g) => [
                'name' => $g->first()->nomenclature?->name ?? '—',
                'days' => $g->max(fn ($b) => $b->daysOnStock()),
                'cost' => $g->sum(fn ($b) => $b->qty_left * $b->unit_cost), 'warn' => true,
            ])->sortByDesc('cost')->take(3)->values();

        // Авто-напоминания
        $reminders = [];
        $paidSale = DB::table('bank_matches')->where('target_type', 'sale')->select('target_id', DB::raw('SUM(amount) p'))->groupBy('target_id')->pluck('p', 'target_id');
        foreach (Sale::with('counterparty')->whereIn('status', ['Выставлен', 'Оплачен'])->get() as $s) {
            $debt = $s->total() - (float) ($paidSale[$s->id] ?? 0);
            if ($debt > 0.01 && $s->date && Carbon::parse($s->date)->diffInDays($now) > 7) {
                $reminders[] = ['kind' => 'auto', 'dot' => 'var(--expense)', 'title' => ($s->counterparty?->name ?? $s->number).' · долг', 'sub' => Carbon::parse($s->date)->diffInDays($now).' дн · '.$this->m($debt)];
            }
        }
        foreach (Shipment::where('status', 'В пути')->whereNotNull('eta')->get() as $s) {
            if (Carbon::parse($s->eta)->isPast()) {
                $reminders[] = ['kind' => 'auto', 'dot' => 'var(--warn)', 'title' => ($s->name ?? $s->number).' · ETA истёк', 'sub' => 'поставка просрочена'];
            }
        }
        if ($unrecLines->count()) {
            $reminders[] = ['kind' => 'auto', 'dot' => '#0a84ff', 'title' => 'Новая выписка', 'sub' => $unrecLines->count().' строк не разнесено'];
        }
        foreach (Note::where('done', false)->latest()->limit(3)->get() as $n) {
            $reminders[] = ['kind' => 'note', 'dot' => '', 'title' => $n->body, 'sub' => 'заметка'];
        }

        // Последние операции
        $tx = BankLine::orderByDesc('date')->orderByDesc('id')->limit(6)->get()->map(fn ($l) => [
            'who' => $l->counterparty_name ?? $l->purpose ?? '—', 'cat' => $l->amount >= 0 ? 'Приход' : 'Расход',
            'amount' => (float) $l->amount, 'kind' => $l->amount >= 0 ? 'in' : 'out',
        ]);

        // Почта (если настроена)
        $mailboxes = MailAccount::with(['messages' => fn ($q) => $q->latest('date')->limit(3)])->get()->map(fn ($a) => [
            'id' => $a->id, 'addr' => $a->email, 'unread' => $a->messages()->where('is_read', false)->count(),
            'letters' => $a->messages->map(fn ($m) => ['from' => $m->from_name, 'sub' => $m->subject]),
        ]);

        return Inertia::render('Dashboard', [
            'kpis' => $kpis,
            'accounts' => $accounts,
            'totalBalance' => $accounts->sum('balance'),
            'monthIn' => $monthIn,
            'monthOut' => $monthOut,
            'unrec' => ['count' => $unrecLines->count(), 'amount' => (float) $unrecLines->sum(fn ($l) => abs($l->amount))],
            'shipments' => $shipments,
            'warehouse' => ['frozen' => round($frozen), 'positions' => $positions, 'stale' => $stale],
            'mailboxes' => $mailboxes,
            'reminders' => $reminders,
            'tx' => $tx,
            'calendarData' => $this->calendar(),
        ]);
    }

    private function calendar(): array
    {
        $data = [];
        foreach (Shipment::where('status', 'В пути')->whereNotNull('eta')->with('counterparty:id,name')->get() as $s) {
            $key = Carbon::parse($s->eta)->toDateString();
            $data[$key][] = ['name' => $s->name ?? $s->number, 'cp' => $s->counterparty?->name ?? '—'];
        }

        return $data;
    }

    private function m(float $n): string
    {
        return number_format(round($n), 0, '.', ' ').' ₽';
    }
}
