<?php

namespace Tests\Unit\Dto;

use App\Dto\CurrencyConversion;
use App\Dto\ListingPricing;
use PHPUnit\Framework\TestCase;

class ListingPricingTest extends TestCase
{
    public function test_the_cost_applies_to_any_price(): void
    {
        $pricing = $this->pricing(8.99);

        $this->assertSame(8.99, $pricing->costAt(0.01));
        $this->assertSame(8.99, $pricing->costAt(10000));
    }

    public function test_the_cost_is_reduced_from_a_price(): void
    {
        $pricing = $this->pricing(8.99, reducedFrom: 150, reducedCost: 4.99);

        $this->assertSame(8.99, $pricing->costAt(149.99));
        $this->assertSame(4.99, $pricing->costAt(150));
        $this->assertSame(4.99, $pricing->costAt(150.01));
    }

    public function test_a_reduction_without_a_reduced_cost_is_free(): void
    {
        $this->assertSame(0.0, $this->pricing(15, reducedFrom: 150)->costAt(150));
    }

    public function test_a_price_that_is_not_positive_has_no_cost(): void
    {
        $pricing = $this->pricing(15);

        $this->assertSame(0.0, $pricing->costAt(0));
        $this->assertSame(0.0, $pricing->costAt(-1));
    }

    public function test_no_cost_by_default(): void
    {
        $this->assertSame(0.0, $this->pricing()->costAt(100));
    }

    public function test_the_total_adds_the_cost_before_converting_and_splitting_into_units(): void
    {
        $pricing = new ListingPricing(new CurrencyConversion('EUR', 'RON', 5), 5);

        // (25 + 5) EUR is 150 RON, 50 RON for each of 3 units.
        $this->assertSame(150.0, $pricing->total(25));
        $this->assertSame(50.0, $pricing->total(25, 3));
    }

    protected function pricing(?float $cost = null, ?float $reducedFrom = null, ?float $reducedCost = null): ListingPricing
    {
        return new ListingPricing(CurrencyConversion::identity('USD'), $cost, $reducedFrom, $reducedCost);
    }
}
