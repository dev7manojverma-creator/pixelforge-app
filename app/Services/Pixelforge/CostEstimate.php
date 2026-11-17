<?php

namespace App\Services\Pixelforge;

/** What a generation is expected to cost the user, before it runs. All amounts in nanodollars. */
final readonly class CostEstimate
{
    public function __construct(
        public int $minChargeNano,
        public int $expectedChargeNano,
        public int $maxChargeNano,
    ) {}
}
