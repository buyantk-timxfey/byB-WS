<?php

namespace App\Services;

use App\Models\Adjustment;
use App\Models\Sale;
use App\Models\Shipment;
use App\Models\Writeoff;
use Illuminate\Support\Carbon;

// Нумерация документов: код месяца + номер = (макс. существующий номер этого типа) + 1.
// Без дыр от удалений: удалил последний — новый займёт его номер; на пустой базе с 1.
class DocNumber
{
    public const MONTHS = [
        1 => 'ЯН', 2 => 'ФВ', 3 => 'МТ', 4 => 'АП', 5 => 'МЙ', 6 => 'ИН',
        7 => 'ИЛ', 8 => 'АВ', 9 => 'СН', 10 => 'ОК', 11 => 'НБ', 12 => 'ДК',
    ];

    private const MODELS = [
        'shipment' => Shipment::class,
        'sale' => Sale::class,
        'writeoff' => Writeoff::class,
        'adjustment' => Adjustment::class,
    ];

    public static function code(Carbon|string $date): string
    {
        $month = $date instanceof Carbon ? $date->month : Carbon::parse($date)->month;

        return self::MONTHS[$month];
    }

    public static function next(string $scope, Carbon|string $date): string
    {
        $model = self::MODELS[$scope] ?? null;
        $max = 0;
        if ($model) {
            foreach ($model::pluck('number') as $num) {
                $n = (int) preg_replace('/^.*-/', '', (string) $num);
                if ($n > $max) {
                    $max = $n;
                }
            }
        }

        return self::code($date).'-'.str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
