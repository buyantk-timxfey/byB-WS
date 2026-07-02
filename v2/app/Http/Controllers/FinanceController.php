<?php

namespace App\Http\Controllers;

use App\Models\BankLine;
use App\Models\ExpenseArticle;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\Turnover;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class FinanceController extends Controller
{
    public function index(Request $r)
    {
        $period = $r->query('period', 'month');
        // Якорь — любая дата внутри просматриваемого периода (листание стрелками)
        $anchor = Carbon::parse($r->query('anchor', now()->toDateString()));
        $now = Carbon::now();

        [$from, $to] = match ($period) {
            'quarter' => [$anchor->copy()->firstOfQuarter(), $anchor->copy()->lastOfQuarter()],
            'year' => [$anchor->copy()->startOfYear(), $anchor->copy()->endOfYear()],
            default => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()],
        };
        $isCurrent = $now->betweenIncluded($from, $to);

        $taxRate = (float) Setting::get('tax_rate', 16) / 100;
        $salaryRate = (float) Setting::get('salary_rate', 20) / 100;

        $pnlFor = function (string $a, string $b) use ($taxRate, $salaryRate): array {
            $sum = fn (string $type) => (float) Turnover::where('type', $type)->whereBetween('date', [$a, $b])->sum('amount');
            $revenue = $sum('income');
            $cogs = $sum('cogs');
            $acquiring = $sum('acquiring');
            $expenses = $sum('expense');
            $otherIncome = $sum('income_other');
            $gross = $revenue - $cogs - $acquiring - $expenses + $otherIncome;
            $tax = round($gross * $taxRate, 2);
            $net = $gross - $tax;
            $salary = round(max(0, $net) * $salaryRate, 2);

            return compact('revenue', 'cogs', 'acquiring', 'expenses', 'otherIncome', 'gross', 'tax', 'net', 'salary')
                + ['retained' => $net - $salary];
        };

        $pnl = $pnlFor($from->toDateString(), $to->toDateString());
        $pnl['salesCount'] = Turnover::where('type', 'income')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->count();

        $margin = $pnl['revenue'] > 0 ? round($pnl['gross'] / $pnl['revenue'] * 100, 1) : 0;
        $costsAll = $pnl['cogs'] + $pnl['acquiring'] + $pnl['expenses'];
        $roi = $costsAll > 0 ? round($pnl['gross'] / $costsAll * 100, 1) : 0;

        // Сравнение: закрытый период — с прошлым периодом целиком; текущий
        // (незакрытый) — с тем же отрезком прошлого, иначе в начале месяца всё «падает».
        [$pa, $pb] = match ($period) {
            'quarter' => [$from->copy()->subQuarterNoOverflow()->firstOfQuarter(), $isCurrent ? $now->copy()->subQuarterNoOverflow() : $from->copy()->subQuarterNoOverflow()->lastOfQuarter()],
            'year' => [$from->copy()->subYear()->startOfYear(), $isCurrent ? $now->copy()->subYearNoOverflow() : $from->copy()->subYear()->endOfYear()],
            default => [$from->copy()->subMonthNoOverflow()->startOfMonth(), $isCurrent ? $now->copy()->subMonthNoOverflow() : $from->copy()->subMonthNoOverflow()->endOfMonth()],
        };
        $pnlP = $pnlFor($pa->toDateString(), $pb->toDateString());
        $marginP = $pnlP['revenue'] > 0 ? $pnlP['gross'] / $pnlP['revenue'] * 100 : 0;
        $costsP = $pnlP['cogs'] + $pnlP['acquiring'] + $pnlP['expenses'];
        $roiP = $costsP > 0 ? $pnlP['gross'] / $costsP * 100 : 0;

        $dPct = function (float $cur, float $prev): array {
            if ($prev <= 0) {
                return ['', false];
            }
            $d = round(($cur - $prev) / $prev * 100);

            return [($d >= 0 ? '+' : '−').abs($d).'%', $d < 0];
        };

        // Расходы по статьям: «на что ушли деньги» за период
        $articleNames = ExpenseArticle::pluck('name', 'id');
        $byArticle = Turnover::where('type', 'expense')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->select('article_id', DB::raw('SUM(amount) s'))->groupBy('article_id')->get()
            ->map(fn ($row) => [
                'article_id' => $row->article_id,
                'name' => $articleNames[$row->article_id] ?? 'Без статьи',
                'sum' => (float) $row->s,
                'acquiring' => false,
            ]);
        if ($pnl['acquiring'] > 0) {
            $byArticle->push(['article_id' => null, 'name' => 'Эквайринг', 'sum' => $pnl['acquiring'], 'acquiring' => true]);
        }
        $byArticle = $byArticle->sortByDesc('sum')->values();

        // Помесячная таблица за 12 месяцев + данные графика прибыли
        $months = [];
        $profit12 = [];
        $monthRows = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = $now->copy()->subMonths($i);
            $row = $pnlFor($m->copy()->startOfMonth()->toDateString(), $m->copy()->endOfMonth()->toDateString());
            $months[] = mb_substr($m->locale('ru')->monthName, 0, 3);
            $profit12[] = round($row['gross'] / 1000, 1);
            $monthRows[] = [
                'label' => mb_convert_case($m->locale('ru')->monthName, MB_CASE_TITLE, 'UTF-8').' '.$m->year,
                'revenue' => $row['revenue'],
                'other' => $row['otherIncome'],
                'costs' => $row['cogs'] + $row['acquiring'] + $row['expenses'],
                'gross' => $row['gross'],
                'tax' => $row['tax'],
                'net' => $row['net'],
                'salary' => $row['salary'],
            ];
        }

        // Налог с начала года — сколько отложить к уплате
        $ytd = $pnlFor($now->copy()->startOfYear()->toDateString(), $now->toDateString());

        return Inertia::render('Finances', [
            'period' => $period,
            'anchor' => $anchor->toDateString(),
            'isCurrent' => $isCurrent,
            'periodLabel' => $this->periodLabel($period, $from),
            'cmpLabel' => $this->cmpLabel($period, $pa, $pb, $isCurrent),
            'taxRate' => (float) Setting::get('tax_rate', 16),
            'salaryRate' => (float) Setting::get('salary_rate', 20),
            'pnl' => $pnl,
            'metrics' => [
                'margin' => $margin,
                'roi' => $roi,
                'avgCheck' => $pnl['salesCount'] > 0 ? round($pnl['revenue'] / $pnl['salesCount']) : 0,
            ],
            'deltas' => [
                'revenue' => $dPct($pnl['revenue'], $pnlP['revenue']),
                'gross' => $dPct($pnl['gross'], $pnlP['gross']),
                'margin' => $dPct($margin, $marginP),
                'roi' => $dPct($roi, $roiP),
            ],
            'byArticle' => $byArticle,
            'monthRows' => $monthRows,
            'taxYtd' => $ytd['tax'],
            'chart' => ['months' => $months, 'profit' => $profit12],
            'drill' => $this->drill($from->toDateString(), $to->toDateString()),
        ]);
    }

    private function periodLabel(string $period, Carbon $from): string
    {
        return match ($period) {
            'quarter' => ['I', 'II', 'III', 'IV'][$from->quarter - 1].' квартал '.$from->year,
            'year' => $from->year.' год',
            default => mb_convert_case($from->locale('ru')->monthName, MB_CASE_TITLE, 'UTF-8').' '.$from->year,
        };
    }

    // Подпись базы сравнения: «июнь 1–2» для текущего периода, «июнь» для закрытого
    private function cmpLabel(string $period, Carbon $pa, Carbon $pb, bool $isCurrent): string
    {
        if ($period === 'year') {
            return $isCurrent ? $pa->year.', те же дни' : (string) $pa->year;
        }
        if ($period === 'quarter') {
            $q = ['I', 'II', 'III', 'IV'][$pa->quarter - 1].' кв.';

            return $isCurrent ? $q.', те же дни' : $q;
        }
        $name = $pa->locale('ru')->monthName;

        return $isCurrent ? $name.' 1–'.$pb->day : $name;
    }

    // Расшифровка по строкам P&L → документы. Для строк выписки показываем
    // контрагента/назначение и статью, а не безликий «Платёж».
    private function drill(string $from, string $to): array
    {
        $rows = Turnover::whereBetween('date', [$from, $to])->orderBy('date')->get();
        $saleNums = Sale::pluck('number', 'id');
        $shipNums = Shipment::pluck('number', 'id');
        $articleNames = ExpenseArticle::pluck('name', 'id');
        $lineIds = $rows->where('doc_type', 'bank_line')->pluck('doc_id')->unique();
        $lines = BankLine::whereIn('id', $lineIds)->get(['id', 'counterparty_name', 'purpose'])->keyBy('id');

        $out = ['income' => [], 'income_other' => [], 'cogs' => [], 'acquiring' => [], 'expense' => []];
        foreach ($rows as $t) {
            $label = match ($t->doc_type) {
                'sale' => 'Продажа '.($saleNums[$t->doc_id] ?? $t->doc_id),
                'shipment' => 'Поставка '.($shipNums[$t->doc_id] ?? $t->doc_id),
                'bank_line' => $lines[$t->doc_id]?->counterparty_name ?? $lines[$t->doc_id]?->purpose ?? 'Платёж',
                'writeoff' => 'Списание',
                'adjustment' => 'Инвентаризация',
                default => $t->doc_type,
            };
            $out[$t->type][] = [
                'doc' => $label,
                'date' => optional($t->date)->toDateString(),
                'sum' => (float) $t->amount,
                'article_id' => $t->article_id,
                'article' => $t->article_id ? ($articleNames[$t->article_id] ?? null) : null,
            ];
        }

        return $out;
    }
}
