<?php

namespace App\Http\Controllers;

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
        $now = Carbon::now();
        // «Месяц» = скользящие 30 дней: календарный месяц в первых числах почти пуст,
        // и отчёт выглядел нулевым, хотя обороты есть.
        [$from, $to] = match ($period) {
            'quarter' => [$now->copy()->firstOfQuarter(), $now->copy()->lastOfQuarter()],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            default => [$now->copy()->subDays(29), $now->copy()],
        };

        $taxRate = (float) Setting::get('tax_rate', 16) / 100;
        $salaryRate = (float) Setting::get('salary_rate', 20) / 100;

        $sum = fn (string $type) => (float) Turnover::where('type', $type)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->sum('amount');

        $revenue = $sum('income');
        $cogs = $sum('cogs');
        $acquiring = $sum('acquiring');
        $expenses = $sum('expense');
        $otherIncome = $sum('income_other');
        $gross = $revenue - $cogs - $acquiring - $expenses + $otherIncome;
        $tax = round($gross * $taxRate, 2);
        $net = $gross - $tax;
        $salary = round(max(0, $net) * $salaryRate, 2);

        $salesCount = Turnover::where('type', 'income')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->count();

        // Динамика прибыли по 12 месяцам
        $months = [];
        $profit12 = [];
        for ($i = 11; $i >= 0; $i--) {
            $m = $now->copy()->subMonths($i);
            $a = $m->copy()->startOfMonth()->toDateString();
            $b = $m->copy()->endOfMonth()->toDateString();
            $g = (float) Turnover::whereIn('type', ['income', 'income_other'])->whereBetween('date', [$a, $b])->sum('amount')
                - (float) Turnover::whereIn('type', ['cogs', 'acquiring', 'expense'])->whereBetween('date', [$a, $b])->sum('amount');
            $months[] = mb_substr($m->locale('ru')->monthName, 0, 3);
            $profit12[] = round($g / 1000, 1);
        }

        return Inertia::render('Finances', [
            'period' => $period,
            'periodLabel' => $period === 'month'
                ? 'последние 30 дней'
                : $from->locale('ru')->isoFormat('MMMM YYYY').' — '.$to->locale('ru')->isoFormat('MMMM YYYY'),
            'taxRate' => (float) Setting::get('tax_rate', 16),
            'salaryRate' => (float) Setting::get('salary_rate', 20),
            'pnl' => compact('revenue', 'cogs', 'acquiring', 'expenses', 'otherIncome', 'gross', 'tax', 'net', 'salary')
                + ['retained' => $net - $salary, 'salesCount' => $salesCount],
            'metrics' => [
                'margin' => $revenue > 0 ? round($gross / $revenue * 100, 1) : 0,
                'roi' => ($cogs + $acquiring + $expenses) > 0 ? round($gross / ($cogs + $acquiring + $expenses) * 100, 1) : 0,
                'avgCheck' => $salesCount > 0 ? round($revenue / $salesCount) : 0,
            ],
            'chart' => ['months' => $months, 'profit' => $profit12],
            'drill' => $this->drill($from->toDateString(), $to->toDateString()),
        ]);
    }

    // Расшифровка по строкам P&L → документы
    private function drill(string $from, string $to): array
    {
        $rows = Turnover::whereBetween('date', [$from, $to])->orderBy('date')->get();
        $saleNums = Sale::pluck('number', 'id');
        $shipNums = Shipment::pluck('number', 'id');
        $out = ['income' => [], 'income_other' => [], 'cogs' => [], 'acquiring' => [], 'expense' => []];
        foreach ($rows as $t) {
            $label = match ($t->doc_type) {
                'sale' => 'Продажа '.($saleNums[$t->doc_id] ?? $t->doc_id),
                'shipment' => 'Поставка '.($shipNums[$t->doc_id] ?? $t->doc_id),
                'bank_line' => 'Платёж',
                'writeoff' => 'Списание',
                'adjustment' => 'Инвентаризация',
                default => $t->doc_type,
            };
            $out[$t->type][] = ['doc' => $label, 'date' => optional($t->date)->toDateString(), 'sum' => (float) $t->amount];
        }

        return $out;
    }
}
