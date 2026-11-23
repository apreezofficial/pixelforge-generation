<?php

namespace App\Support;

/**
 * All money is stored as integer nanodollars (1 USD = 1_000_000_000 nano).
 * Pixelforge reports costs with nine decimal places, so nanodollars are exact.
 */
final class Money
{
    public const NANO_PER_USD = 1_000_000_000;

    /** Convert a decimal USD string such as "0.169080000" into nanodollars. */
    public static function fromUsdString(string $usd): int
    {
        $usd = trim($usd);
        $negative = str_starts_with($usd, '-');
        $usd = ltrim($usd, '-');

        [$whole, $fraction] = array_pad(explode('.', $usd, 2), 2, '');

        if (strlen($fraction) > 9) {
            throw new \InvalidArgumentException("Money has more than 9 decimal places: {$usd}");
        }

        $nano = ((int) $whole) * self::NANO_PER_USD + (int) str_pad($fraction, 9, '0');

        return $negative ? -$nano : $nano;
    }

    /** Format nanodollars as a USD string, e.g. 169080000 -> "0.169080000". */
    public static function toUsdString(int $nano): string
    {
        $sign = $nano < 0 ? '-' : '';

        return $sign.intdiv(abs($nano), self::NANO_PER_USD).'.'
            .str_pad((string) (abs($nano) % self::NANO_PER_USD), 9, '0', STR_PAD_LEFT);
    }

    /** Human display: shows up to 6 decimals, which covers sub-cent costs. */
    public static function display(int $nano): string
    {
        $value = number_format($nano / self::NANO_PER_USD, 6, '.', '');

        return '$'.$value;
    }

    /**
     * Convert a rate string such as "12.50" (USD per 1M tokens) into hundredths
     * of a dollar as an integer (1250). Rates are always quoted to two decimals.
     */
    public static function rateToHundredths(string $rate): int
    {
        return Money::fromUsdString($rate) / 10_000_000; // nano -> hundredths
    }

    /**
     * Cost in nanodollars of `$tokens` billed at a rate quoted per 1M tokens.
     * 1 token at $5.00/M = $0.000005 = 5_000 nano, and rateHundredths is 500,
     * so nano = tokens * rateHundredths * 10. Exact, integer only.
     */
    public static function tokensCost(int $tokens, int $rateHundredths): int
    {
        return $tokens * $rateHundredths * 10;
    }

    /** Customer charge for a provider cost: cost * numerator / denominator, rounded up to the nano. */
    public static function withMarkup(int $providerNano, int $numerator, int $denominator): int
    {
        return intdiv($providerNano * $numerator + $denominator - 1, $denominator);
    }
}
