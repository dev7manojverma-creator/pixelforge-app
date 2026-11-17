<?php

namespace App\Services\Pixelforge;

use App\Support\Money;

/**
 * Turns Pixelforge token counts and rates into money.
 *
 * Pure integer arithmetic: tokens × nanodollars-per-token.
 */
final class Pricing
{
    public const TOKEN_TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /**
     * What Pixelforge bills us for the given usage, in nanodollars.
     *
     * @param  array<string, int>  $usage  token counts keyed by token type
     * @param  array<string, string>  $rates  USD per 1M tokens keyed by token type
     */
    public static function providerCostNano(array $usage, array $rates): int
    {
        $total = 0;

        foreach (self::TOKEN_TYPES as $type) {
            $total += (int) ($usage[$type] ?? 0) * Money::nanoPerToken((string) $rates[$type]);
        }

        return $total;
    }

    /**
     * What the user pays: provider cost plus our markup.
     *
     * A 25% markup can land on a fraction of a nanodollar; we round that up, so
     * the user is never charged less than cost + markup and the difference is
     * always under one nanodollar.
     */
    public static function chargeNano(int $providerCostNano, ?int $markupBps = null): int
    {
        $markupBps ??= (int) config('pixelforge.markup_bps');

        return intdiv($providerCostNano * (10_000 + $markupBps) + 9_999, 10_000);
    }

    /**
     * Estimate the charge for a request from the published pricing document.
     *
     * Output and reference-image tokens are exact. Text tokens are within a
     * published variance of ceil(chars / 4) and reasoning tokens fall in a
     * per-quality range, so we return the lowest, the expected (mid-range
     * reasoning) and the highest possible charge.
     *
     * @param  array  $pricing  the GET /v1/pricing document
     */
    public static function estimate(array $pricing, string $prompt, string $size, string $quality, int $referenceImages): CostEstimate
    {
        $tokens = $pricing['tokens'];
        $rates = $pricing['rates'];

        $text = (int) ceil(mb_strlen($prompt) / (int) $tokens['characters_per_text_token']);
        $variance = (float) $tokens['text_token_variance'];
        // The epsilon keeps float noise (e.g. 100 × 1.15 = 114.99999999999999) from moving a boundary.
        $textMin = (int) floor($text * (1 - $variance) + 1e-9);
        $textMax = (int) ceil($text * (1 + $variance) - 1e-9);

        $reasoningMin = (int) $tokens['reasoning'][$quality]['min'];
        $reasoningMax = (int) $tokens['reasoning'][$quality]['max'];

        $fixed = [
            'input_image_tokens' => $referenceImages * (int) $tokens['per_reference_image'],
            'output_tokens' => (int) $tokens['output'][$quality][$size],
        ];

        $charge = fn (int $textTokens, int $reasoningTokens) => self::chargeNano(self::providerCostNano(
            $fixed + ['input_text_tokens' => $textTokens, 'reasoning_tokens' => $reasoningTokens],
            $rates,
        ));

        return new CostEstimate(
            minChargeNano: $charge($textMin, $reasoningMin),
            expectedChargeNano: $charge($text, intdiv($reasoningMin + $reasoningMax, 2)),
            maxChargeNano: $charge($textMax, $reasoningMax),
        );
    }
}
