<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Money is handled as whole nanodollars (1 USD = 1,000,000,000) in plain integers.
 *
 * Pixelforge reports amounts with nine decimal places and never rounds, so a
 * nanodollar is the smallest unit we can ever see. Floats are never used.
 */
final class Money
{
    public const NANO_PER_DOLLAR = 1_000_000_000;

    /** "0.046125000" (USD) → 46125000 (nanodollars). */
    public static function nanoFromDecimal(string $usd): int
    {
        return self::scale($usd, 9);
    }

    /** 46125000 → "0.046125000". */
    public static function decimalFromNano(int $nano): string
    {
        $sign = $nano < 0 ? '-' : '';
        $nano = abs($nano);

        return sprintf('%s%d.%09d', $sign, intdiv($nano, self::NANO_PER_DOLLAR), $nano % self::NANO_PER_DOLLAR);
    }

    /**
     * A rate in USD per 1M tokens ("12.50") → nanodollars per single token (12500).
     *
     * $1 per 1M tokens is exactly 1,000 nanodollars per token, so any rate with
     * up to three decimals is exact. Anything finer would not fit and is refused
     * rather than rounded.
     */
    public static function nanoPerToken(string $usdPerMillionTokens): int
    {
        return self::scale($usdPerMillionTokens, 3);
    }

    /** Parse a non-negative decimal string into an integer scaled by 10^$decimals, exactly. */
    private static function scale(string $value, int $decimals): int
    {
        if (! preg_match('/^(\d+)(?:\.(\d+))?$/', $value, $m)) {
            throw new InvalidArgumentException("Not a decimal amount: [{$value}]");
        }

        $fraction = $m[2] ?? '';

        if (strlen($fraction) > $decimals) {
            if (trim(substr($fraction, $decimals), '0') !== '') {
                throw new InvalidArgumentException("Amount [{$value}] is more precise than {$decimals} decimals.");
            }
            $fraction = substr($fraction, 0, $decimals);
        }

        return (int) ($m[1].str_pad($fraction, $decimals, '0'));
    }
}
