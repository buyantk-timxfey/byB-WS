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
        // Текущий календарный месяц. Дельты — против того же отрезка прошлого
        // месяца (1–2 июля сравниваются с 1–2 июня), иначе в начале месяца все
        // стрелки красные просто потому, что месяц только начался.
        $from = $now->copy()->startOfMonth()->toDateString();
        // Верхняя граница — конец сегодняшнего дня: колонка date хранится как
        // «Y-m-d 00:00:00», и с date-only границей записи за сегодня выпадали из whereBetween.
        $to = $now->copy()->endOfDay()->toDateTimeString();
        $staleDays = (int) Setting::get('stale_days', 60);

        $purchases = (float) Shipment::whereNotNull('posted_at')->whereBetween('date', [$from, $to])->get()->sum(fn ($s) => $s->total());

        // спарклайны: 8 мес
        $spark = fn (callable $f) => collect(range(7, 0))->map(function ($i) use ($now, $f) {
            $m = $now->copy()->subMonths($i);
            return $f($m->copy()->startOfMonth()->toDateString(), $m->copy()->endOfMonth()->toDateString());
        })->all();
        // Спарклайны для месячных операционных KPI (8 месяцев). Финансовые метрики
        // (выручка/прибыль/маржа/ROI) переехали в раздел «Финансы» — здесь их нет.
        $salesSpark = $spark(fn ($a, $b) => round(Sale::whereBetween('date', [$a, $b])->get()->sum(fn ($s) => $s->total()) / 1000));
        $purchSpark = $spark(fn ($a, $b) => round((float) Shipment::whereNotNull('posted_at')
            ->whereBetween('date', [$a, $b])->get()->sum(fn ($s) => $s->total()) / 1000));

        // Тот же отрезок прошлого месяца — для динамики KPI
        $prev = $now->copy()->subMonthNoOverflow();
        $pa = $prev->copy()->startOfMonth()->toDateString();
        $pb = $prev->copy()->endOfDay()->toDateTimeString();
        $purchP = (float) Shipment::whereNotNull('posted_at')->whereBetween('date', [$pa, $pb])->get()->sum(fn ($s) => $s->total());

        // Все дельты — в процентах к прошлому значению («пп» не используем)
        $dPct = function (float $cur, float $prev): array {
            if ($prev <= 0) {
                return ['', false];
            }
            $d = round(($cur - $prev) / $prev * 100);
            return [($d >= 0 ? '+' : '−').abs($d).'%', $d < 0];
        };
        [$pD, $pDownRaw] = $dPct($purchases, $purchP);

        // Долг покупателей: выставленные и недоплаченные продажи (живые деньги в пути)
        $paidSale = DB::table('bank_matches')->where('target_type', 'sale')->select('target_id', DB::raw('SUM(amount) p'))->groupBy('target_id')->pluck('p', 'target_id');
        $debtSales = [];
        $debtTotal = 0.0;
        foreach (Sale::with('counterparty')->whereIn('status', ['Выставлен', 'Оплачен'])->get() as $s) {
            $d = $s->total() - (float) ($paidSale[$s->id] ?? 0) - (float) $s->fee_writeoff;
            if ($d > 0.01) {
                $debtSales[] = [$s, $d];
                $debtTotal += $d;
            }
        }

        // ── Операционные показатели дашборда («сейчас» и «за месяц») ──
        $accounts = Account::orderBy('sort_order')->orderBy('name')->get()->map(fn ($a) => ['name' => $a->name, 'balance' => $a->balance()]);
        $totalBalance = $accounts->sum('balance');

        // Денег в пути: сумма поставок в статусе «В пути» (товар оплачен/едет)
        $transit = Shipment::where('status', 'В пути')->get();
        $transitMoney = $transit->sum(fn ($s) => $s->total());
        $transitCount = $transit->count();

        // Долг поставщикам: неоплаченный остаток по всем поставкам
        $paidShip = DB::table('bank_matches')->where('target_type', 'shipment')
            ->select('target_id', DB::raw('SUM(amount) p'))->groupBy('target_id')->pluck('p', 'target_id');
        $supplierDebt = 0.0;
        $supDebtCount = 0;
        foreach (Shipment::all() as $s) {
            $d = $s->total() - (float) ($paidShip[$s->id] ?? 0);
            if ($d > 0.01) {
                $supplierDebt += $d;
                $supDebtCount++;
            }
        }

        // Продажи за месяц: сумма, количество, сколько уже оплачено покупателем
        $salesMonth = Sale::whereBetween('date', [$from, $to])->get();
        $salesSum = $salesMonth->sum(fn ($s) => $s->total());
        $salesCount = $salesMonth->count();
        $salesPaid = $salesMonth->where('status', 'Оплачен')->count();
        $salesPrev = (float) Sale::whereBetween('date', [$pa, $pb])->get()->sum(fn ($s) => $s->total());
        [$salesD, $salesDown] = $dPct($salesSum, $salesPrev);

        // Поставки за месяц: количество заведённых и сколько уже завершено (сумма закупок — $purchases)
        $shipMonth = Shipment::whereBetween('date', [$from, $to])->get();
        $shipCount = $shipMonth->count();
        $shipDone = $shipMonth->where('status', 'Завершено')->count();

        $kpis = [
            ['label' => 'Денег в пути', 'value' => $this->m($transitMoney), 'delta' => '', 'down' => false,
                'sub' => $this->plural($transitCount, 'поставка едет', 'поставки едут', 'поставок едут'), 'spark' => [], 'color' => 'var(--info)', 'href' => '/shipments'],
            ['label' => 'Долг покупателей', 'value' => $this->m($debtTotal), 'delta' => '', 'down' => false,
                'sub' => $this->salesWord(count($debtSales)).' с долгом', 'spark' => [], 'color' => 'var(--warn)', 'href' => '/sales'],
            ['label' => 'Долг поставщикам', 'value' => $this->m($supplierDebt), 'delta' => '', 'down' => false,
                'sub' => $this->plural($supDebtCount, 'поставка', 'поставки', 'поставок'), 'spark' => [], 'color' => 'var(--expense)', 'href' => '/shipments'],
            ['label' => 'На счетах всего', 'value' => $this->m($totalBalance), 'delta' => '', 'down' => false,
                'sub' => $this->plural($accounts->count(), 'счёт', 'счёта', 'счетов'), 'spark' => [], 'color' => 'var(--income)', 'href' => '/bank'],
            ['label' => 'Продажи за месяц', 'value' => $this->m($salesSum), 'delta' => $salesD, 'down' => $salesDown,
                'sub' => $this->salesWord($salesCount).' · оплачено '.$salesPaid, 'spark' => $salesSpark, 'color' => 'var(--income)', 'href' => '/sales'],
            ['label' => 'Закупки за месяц', 'value' => $this->m($purchases), 'delta' => $pD, 'down' => true,
                'sub' => $this->plural($shipCount, 'поставка', 'поставки', 'поставок').' · завершено '.$shipDone, 'spark' => $purchSpark, 'color' => 'var(--expense)', 'href' => '/shipments'],
        ];
        $unrecLines = BankLine::whereIn('status', ['unmatched', 'partial'])->get();
        // Приход/расход за месяц (по строкам выписки)
        $monthIn = (float) BankLine::whereBetween('date', [$from, $to])->where('amount', '>', 0)->sum('amount');
        $monthOut = abs((float) BankLine::whereBetween('date', [$from, $to])->where('amount', '<', 0)->sum('amount'));

        // Трекер поставок (кроме завершённых): маршрут с грузовиком, остаток дней,
        // цвет-статус для свечения карточки (ok / warn ≤2 дн до ETA / bad / wait).
        $shipments = Shipment::with(['counterparty:id,name', 'etaChanges', 'items', 'receipts'])->where('status', '!=', 'Завершено')
            ->get()->map(function (Shipment $s) use ($now) {
                $start = $s->date ? Carbon::parse($s->date) : null;
                $eta = $s->eta ? Carbon::parse($s->eta) : null;
                $overdue = $eta && $eta->isPast();
                $wait = $s->status === 'Ожидает отправки';
                $pct = 0;
                if ($start && $eta && $eta->gt($start)) {
                    $pct = (int) min(100, max(0, round(Carbon::now()->diffInDays($start, false) * -1 / $start->diffInDays($eta) * 100)));
                }
                if ($overdue) {
                    $pct = 100;
                }
                $kind = $overdue ? 'overdue' : ($wait ? 'wait' : 'pct');
                $days = $eta ? (int) $now->copy()->startOfDay()->diffInDays($eta->copy()->startOfDay(), false) : null;
                $glow = $overdue ? 'bad' : ($wait ? 'wait' : (($days !== null && $days <= 2) ? 'warn' : 'ok'));

                // Перенос ETA: участок маршрута от первоначального срока до нового — пунктиром
                $firstEta = optional($s->etaChanges->sortBy('id')->first())->old_eta;
                $shift = ($firstEta && $eta) ? (int) $firstEta->copy()->startOfDay()->diffInDays($eta->copy()->startOfDay(), false) : 0;
                $shiftPct = null;
                if ($shift > 0 && $start && $eta && $eta->gt($start)) {
                    $shiftPct = (int) min(99, max(0, round($start->diffInDays($firstEta, false) / $start->diffInDays($eta) * 100)));
                }

                // Частичная приёмка: сколько уже приехало и когда была последняя приёмка
                $totalQty = (float) $s->items->sum('qty');
                $receivedQty = (float) $s->items->sum('qty_received');
                $lastReceipt = optional($s->receipts->sortByDesc('id')->first())->date;

                return [
                    'id' => $s->id, 'status' => $s->status,
                    'cp' => $s->counterparty?->name ?? '—', 'name' => $s->name ?? $s->number,
                    'kind' => $kind, 'pct' => $pct, 'glow' => $glow, 'days' => $days,
                    'shift' => $shift, 'shiftPct' => $shiftPct,
                    'received' => $receivedQty, 'totalQty' => $totalQty,
                    'lastReceipt' => $lastReceipt ? $lastReceipt->format('d.m') : null,
                    'start' => $start ? $start->format('d.m') : '—',
                    'eta' => $eta ? $eta->format('d.m') : '—',
                ];
            })
            // Сортировка по срочности: сначала просроченные (самые давние сверху),
            // затем в пути по ближайшему ETA, в конце — без даты («Ожидает»).
            // Массив-ключ сравнивается поэлементно: [группа, дни].
            ->sortBy(fn ($r) => [
                $r['glow'] === 'bad' ? 0 : ($r['days'] === null ? 2 : 1),
                $r['days'] ?? PHP_INT_MAX,
            ])->values();

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

        // Авто-напоминания (долги уже посчитаны для KPI «Долг покупателей»)
        $reminders = [];
        foreach ($debtSales as [$s, $debt]) {
            if ($s->date && Carbon::parse($s->date)->diffInDays($now) > 7) {
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

    // «1 продажа / 2 продажи / 5 продаж»
    private function salesWord(int $n): string
    {
        $m10 = $n % 10;
        $m100 = $n % 100;
        $word = ($m10 === 1 && $m100 !== 11) ? 'продажа'
            : (($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) ? 'продажи' : 'продаж');

        return $n.' '.$word;
    }

    // Универсальное склонение: plural(3, 'поставка', 'поставки', 'поставок') → «3 поставки»
    private function plural(int $n, string $one, string $few, string $many): string
    {
        $m10 = $n % 10;
        $m100 = $n % 100;
        $word = ($m10 === 1 && $m100 !== 11) ? $one
            : (($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) ? $few : $many);

        return $n.' '.$word;
    }
}
