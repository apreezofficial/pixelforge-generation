<?php

namespace Tests\Unit;

use App\Services\Pricing;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_usd_string_round_trips_exactly(): void
    {
        $this->assertSame(169080000, Money::fromUsdString('0.169080000'));
        $this->assertSame('0.169080000', Money::toUsdString(169080000));
        $this->assertSame(1, Money::fromUsdString('0.000000001'));
    }

    public function test_provider_cost_matches_pixelforge_usage_example(): void
    {
        // Usage example from the API docs: 41 text, 0 image, 3300 output, 2950 reasoning.
        // Expected cost_usd is 0.169080000.
        $rates = ['input_text' => '5.00', 'input_image' => '10.00', 'output' => '40.00', 'reasoning' => '12.50'];

        $nano = (new Pricing)->providerCost(
            ['input_text' => 41, 'input_image' => 0, 'output' => 3300, 'reasoning' => 2950],
            $rates,
        );

        $this->assertSame(169080000, $nano);
    }

    public function test_markup_rounds_up_to_the_nano(): void
    {
        $this->assertSame(2, Money::withMarkup(1, 5, 4));   // 1.25 -> 2
        $this->assertSame(5, Money::withMarkup(4, 5, 4));   // exact
        $this->assertSame(211350000, Money::withMarkup(169080000, 5, 4));
    }
}
