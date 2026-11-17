<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_converts_decimal_dollars_to_nanodollars_exactly(): void
    {
        $this->assertSame(46_125_000, Money::nanoFromDecimal('0.046125000'));
        $this->assertSame(169_080_000, Money::nanoFromDecimal('0.169080000'));
        $this->assertSame(5_000_000_000, Money::nanoFromDecimal('5'));
        $this->assertSame(1, Money::nanoFromDecimal('0.000000001'));
    }

    public function test_it_formats_nanodollars_with_nine_decimals(): void
    {
        $this->assertSame('0.046125000', Money::decimalFromNano(46_125_000));
        $this->assertSame('12.000000001', Money::decimalFromNano(12_000_000_001));
        $this->assertSame('-0.500000000', Money::decimalFromNano(-500_000_000));
    }

    public function test_one_dollar_per_million_tokens_is_a_thousand_nanodollars_per_token(): void
    {
        $this->assertSame(1_000, Money::nanoPerToken('1.00'));
        $this->assertSame(12_500, Money::nanoPerToken('12.50'));
        $this->assertSame(5_000, Money::nanoPerToken('5'));
        $this->assertSame(1, Money::nanoPerToken('0.001'));
    }

    public function test_it_refuses_to_round_a_rate_it_cannot_represent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::nanoPerToken('0.0005');
    }

    public function test_it_refuses_anything_that_is_not_a_plain_decimal(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::nanoFromDecimal('1e-3');
    }
}
