<?php

namespace Tests\Unit;

use App\Services\Pixelforge\Pricing;
use Database\Factories\GenerationFactory;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class PricingTest extends TestCase
{
    use PixelforgeFixtures;

    public function test_provider_cost_matches_the_worked_example_in_the_docs(): void
    {
        // "The most a generation can cost": $0.373875
        $cost = Pricing::providerCostNano([
            'input_text_tokens' => 115,
            'input_image_tokens' => 1530,
            'output_tokens' => 6450,
            'reasoning_tokens' => 8000,
        ], GenerationFactory::RATES);

        $this->assertSame(373_875_000, $cost);
    }

    public function test_the_usage_example_in_the_docs_adds_up(): void
    {
        // GET /v1/usage example: 41 text + 3300 output + 2950 reasoning = $0.169080000
        $cost = Pricing::providerCostNano([
            'input_text_tokens' => 41,
            'input_image_tokens' => 0,
            'output_tokens' => 3300,
            'reasoning_tokens' => 2950,
        ], GenerationFactory::RATES);

        $this->assertSame(169_080_000, $cost);
    }

    public function test_users_pay_cost_plus_a_25_percent_markup(): void
    {
        $this->assertSame(467_343_750, Pricing::chargeNano(373_875_000, 2500));
        $this->assertSame(0, Pricing::chargeNano(0, 2500));
    }

    public function test_a_fraction_of_a_nanodollar_is_rounded_up_never_down(): void
    {
        // 5,001 × 1.25 = 6,251.25
        $this->assertSame(6_252, Pricing::chargeNano(5_001, 2500));
        // 5,000 × 1.25 = 6,250 exactly: nothing to round.
        $this->assertSame(6_250, Pricing::chargeNano(5_000, 2500));
    }

    public function test_the_estimate_brackets_every_possible_outcome(): void
    {
        $estimate = Pricing::estimate($this->pricingDocument(), str_repeat('a', 400), '1536x1024', 'high', 2);

        // Highest: 115 text, 8,000 reasoning → the docs' maximum, plus markup.
        $this->assertSame(Pricing::chargeNano(373_875_000), $estimate->maxChargeNano);

        // Lowest: 85 text tokens, 1,000 reasoning tokens.
        $lowest = 85 * 5_000 + 1530 * 10_000 + 6450 * 40_000 + 1000 * 12_500;
        $this->assertSame(Pricing::chargeNano($lowest), $estimate->minChargeNano);

        // Expected: 100 text tokens, mid-range (4,500) reasoning tokens.
        $expected = 100 * 5_000 + 1530 * 10_000 + 6450 * 40_000 + 4500 * 12_500;
        $this->assertSame(Pricing::chargeNano($expected), $estimate->expectedChargeNano);
    }

    public function test_text_tokens_count_characters_not_bytes_and_round_up(): void
    {
        $pricing = $this->pricingDocument();

        // Five multibyte characters → ceil(5 / 4) = 2 tokens, same as five ASCII characters.
        $this->assertEquals(
            Pricing::estimate($pricing, 'ñandú', '1024x1024', 'low', 0),
            Pricing::estimate($pricing, 'abcde', '1024x1024', 'low', 0),
        );
    }
}
