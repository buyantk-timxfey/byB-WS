<?php

namespace App\Services;

use App\Models\BankLine;
use App\Models\Sale;
use App\Models\Settlement;
use App\Models\Shipment;
use App\Models\Turnover;
use Illuminate\Support\Facades\DB;

// Сверка строки выписки с документами/статьями (statement-first, кассовый метод).
// Деньги уже учтены суммой строки в балансе счёта — здесь только начисления:
// выручка/расход (P&L) и гашение взаиморасчётов. Идемпотентна: пересоздаёт эффекты.
class BankReconcile
{
    // match.amount — абсолютная сумма, отнесённая на цель (для частичной/мульти-привязки).
    public static function apply(BankLine $line): void
    {
        $line->load('matches');

        DB::transaction(function () use ($line) {
            // Снять прежние эффекты этой строки
            Settlement::where('doc_type', 'bank_line')->where('doc_id', $line->id)->delete();
            Turnover::where('doc_type', 'bank_line')->where('doc_id', $line->id)->delete();

            $allocated = 0.0;
            foreach ($line->matches as $m) {
                $amt = abs((float) $m->amount);
                $allocated += $amt;

                switch ($m->target_type) {
                    case 'sale':
                        $sale = Sale::find($m->target_id);
                        if ($sale?->counterparty_id) {
                            // гасим дебиторку покупателя
                            Settlement::create([
                                'counterparty_id' => $sale->counterparty_id, 'date' => $line->date,
                                'amount' => -$amt, 'doc_type' => 'bank_line', 'doc_id' => $line->id,
                                'comment' => 'Оплата по '.$sale->number,
                            ]);
                        }
                        // выручка (кассовый метод)
                        Turnover::create([
                            'date' => $line->date, 'type' => 'income', 'amount' => $amt,
                            'doc_type' => 'bank_line', 'doc_id' => $line->id,
                        ]);
                        break;

                    case 'shipment':
                        $shipment = Shipment::find($m->target_id);
                        if ($shipment?->counterparty_id) {
                            // уменьшаем кредиторку поставщику (мы заплатили)
                            Settlement::create([
                                'counterparty_id' => $shipment->counterparty_id, 'date' => $line->date,
                                'amount' => $amt, 'doc_type' => 'bank_line', 'doc_id' => $line->id,
                                'comment' => 'Оплата поставки '.$shipment->number,
                            ]);
                        }
                        break;

                    case 'expense_article':
                        Turnover::create([
                            'date' => $line->date, 'type' => 'expense', 'article_id' => $m->target_id,
                            'amount' => $amt, 'doc_type' => 'bank_line', 'doc_id' => $line->id,
                        ]);
                        break;

                    case 'income_article':
                        // Прочие доходы (кэшбэк, проценты) — отдельный тип, чтобы
                        // не смешивались с выручкой от продаж (income)
                        Turnover::create([
                            'date' => $line->date, 'type' => 'income_other', 'article_id' => $m->target_id,
                            'amount' => $amt, 'doc_type' => 'bank_line', 'doc_id' => $line->id,
                        ]);
                        break;

                    case 'acquiring':
                        // Отдельного типа «эквайринг» в финансах больше нет —
                        // реальные комиссии из выписки идут расходом по статье «Эквайринг»
                        Turnover::create([
                            'date' => $line->date, 'type' => 'expense',
                            'article_id' => $m->target_id ?? \App\Models\ExpenseArticle::firstOrCreate(
                                ['name' => 'Эквайринг'],
                                ['kind' => 'expense', 'is_system' => true],
                            )->id,
                            'amount' => $amt, 'doc_type' => 'bank_line', 'doc_id' => $line->id,
                        ]);
                        break;

                    case 'transfer':
                    case 'other':
                    default:
                        // Перевод между своими счетами / прочее — вне P&L и взаиморасчётов
                        break;
                }
            }

            // Статус разнесения
            $lineAbs = abs((float) $line->amount);
            if ($line->status !== 'ignore') {
                if ($allocated <= 0.001) {
                    $line->status = 'unmatched';
                } elseif (abs($allocated - $lineAbs) <= 0.01) {
                    $line->status = 'matched';
                } else {
                    $line->status = 'partial';
                }
                $line->save();
            }
        });
    }
}
