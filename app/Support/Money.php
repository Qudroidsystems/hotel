<?php

namespace App\Support;

/**
 * Rounding money to 2 decimals the same way everywhere — here and in the
 * till's JavaScript (pos-terminal.js round2), so a till that worked out a
 * total offline always agrees with the server to the kobo.
 * Half rounds away from zero, after trimming floating-point noise.
 */
final class Money
{
    public static function round(float|int|string $amount): float
    {
        $cents = (float) sprintf('%.15g', (float) $amount * 100);   // 1.005*100 = 100.49999… → 100.5
        $rounded = floor(abs($cents) + 0.5) / 100;

        return $cents < 0 ? -$rounded : $rounded;
    }
}
