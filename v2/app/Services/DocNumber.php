<?php

namespace App\Services;

use App\Models\DocSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Нумерация документов: код месяца + сквозной счётчик (никогда не обнуляется).
// Префикс отражает месяц документа: ИН-0043 (июнь), ИЛ-0044 (июль)…
class DocNumber
{
    // Двухбуквенные коды месяцев (июнь=ИН, июль=ИЛ — как просил пользователь)
    public const MONTHS = [
        1 => 'ЯН', 2 => 'ФВ', 3 => 'МТ', 4 => 'АП', 5 => 'МЙ', 6 => 'ИН',
        7 => 'ИЛ', 8 => 'АВ', 9 => 'СН', 10 => 'ОК', 11 => 'НБ', 12 => 'ДК',
    ];

    public static function code(Carbon|string $date): string
    {
        $month = $date instanceof Carbon ? $date->month : Carbon::parse($date)->month;

        return self::MONTHS[$month];
    }

    // Выдать следующий номер для документа данного типа на заданную дату.
    public static function next(string $scope, Carbon|string $date): string
    {
        return DB::transaction(function () use ($scope, $date) {
            $seq = DocSequence::lockForUpdate()->firstOrCreate(['scope' => $scope], ['last_number' => 0]);
            $seq->increment('last_number');

            return self::code($date).'-'.str_pad((string) $seq->last_number, 4, '0', STR_PAD_LEFT);
        });
    }
}
