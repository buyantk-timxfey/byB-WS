<?php

namespace App\Services;

use Illuminate\Support\Carbon;

// Парсер банковской выписки формата 1CClientBankExchange (обычно Windows-1251).
// Возвращает шапку счёта и список документов со знаком суммы (приход +/расход −).
class BankStatementParser
{
    public static function parse(string $raw): array
    {
        // Декодируем из Windows-1251, если файл не в UTF-8
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
        }
        $lines = preg_split('/\r\n|\r|\n/', $raw);

        $rasch = null;
        $header = ['opening' => 0, 'closing' => 0, 'from' => null, 'to' => null];
        $docs = [];
        $cur = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$key, $val] = array_pad(explode('=', $line, 2), 2, '');

            switch ($key) {
                case 'РасчСчет':
                    if ($cur === null) {
                        $rasch = $val;
                    } // РасчСчет внутри документа игнорируем
                    break;
                case 'НачальныйОстаток': $header['opening'] = (float) $val; break;
                case 'КонечныйОстаток': $header['closing'] = (float) $val; break;
                case 'ДатаНачала': $header['from'] = self::date($val); break;
                case 'ДатаКонца': $header['to'] = self::date($val); break;

                case 'СекцияДокумент':
                    $cur = ['number' => null, 'date' => null, 'amount' => 0, 'payer_acc' => null,
                        'payer' => null, 'payer_inn' => null, 'receiver_acc' => null,
                        'receiver' => null, 'receiver_inn' => null, 'purpose' => null];
                    break;
                case 'Номер': if ($cur) $cur['number'] = $val; break;
                case 'Дата': if ($cur) $cur['date'] = self::date($val); break;
                case 'Сумма': if ($cur) $cur['amount'] = (float) $val; break;
                case 'ПлательщикСчет': if ($cur) $cur['payer_acc'] = $val; break;
                // Некоторые банки (Точка, Озон Банк) пишут имя с суффиксом «1»
                // (Плательщик1/Получатель1) вместо обычных Плательщик/Получатель —
                // без этого имя контрагента терялось и строка показывалась безымянной.
                case 'Плательщик':
                case 'Плательщик1': if ($cur) $cur['payer'] = self::cleanName($val); break;
                case 'ПлательщикИНН': if ($cur) $cur['payer_inn'] = $val; break;
                case 'ПолучательСчет': if ($cur) $cur['receiver_acc'] = $val; break;
                case 'Получатель':
                case 'Получатель1': if ($cur) $cur['receiver'] = self::cleanName($val); break;
                case 'ПолучательИНН': if ($cur) $cur['receiver_inn'] = $val; break;
                case 'НазначениеПлатежа': if ($cur) $cur['purpose'] = $val; break;

                case 'КонецДокумента':
                    if ($cur) {
                        $docs[] = self::normalize($cur, $rasch);
                        $cur = null;
                    }
                    break;
            }
        }

        return ['rasch' => $rasch, 'header' => $header, 'docs' => $docs];
    }

    // Приведение документа к строке выписки: знак суммы + контрагент = «другая сторона»
    private static function normalize(array $d, ?string $rasch): array
    {
        $isExpense = $rasch && $d['payer_acc'] === $rasch;   // наш счёт — плательщик → расход
        $amount = $isExpense ? -abs($d['amount']) : abs($d['amount']);
        $partyName = $isExpense ? $d['receiver'] : $d['payer'];
        $partyInn = $isExpense ? $d['receiver_inn'] : $d['payer_inn'];

        return [
            'number' => $d['number'],
            'date' => $d['date'],
            'amount' => $amount,
            'counterparty_name' => $partyName,
            'inn' => $partyInn,
            'purpose' => $d['purpose'],
            'dedup' => md5(($d['number'] ?? '').'|'.$d['amount'].'|'.($d['date'] ?? '').'|'.($rasch ?? '')),
        ];
    }

    private static function date(?string $v): ?string
    {
        if (! $v) {
            return null;
        }
        try {
            return Carbon::createFromFormat('d.m.Y', trim($v))->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function cleanName(?string $v): ?string
    {
        if (! $v) {
            return null;
        }
        // часто имя идёт как «ИНН/КПП Наименование» — берём хвост
        return trim(preg_replace('/^\d{10,12}(\/\d+)?\s*/', '', $v)) ?: $v;
    }
}
