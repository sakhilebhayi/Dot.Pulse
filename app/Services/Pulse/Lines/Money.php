<?php

namespace App\Services\Pulse\Lines;

class Money
{
    public static function text(?int $cents, ?string $currency = 'ZAR'): string
    {
        $currency ??= 'ZAR';
        if ($cents === null) {
            return '';
        }

        $symbol = $currency === 'ZAR' ? 'R' : $currency.' ';
        $whole = intdiv($cents, 100);
        $rest = $cents % 100;

        return $symbol.number_format($whole, 0, '.', ' ').($rest ? sprintf('.%02d', $rest) : '');
    }
}
