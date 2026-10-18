<?php

namespace Tests\Unit\Dto;

use App\Dto\AdditionalCost;
use PHPUnit\Framework\TestCase;

class AdditionalCostTest extends TestCase
{
    public function test_the_cost_applies_to_any_price(): void
    {
        $cost = new AdditionalCost(8.99);

        $this->assertSame(8.99, $cost->on(0.01));
        $this->assertSame(8.99, $cost->on(10000));
    }

    public function test_the_cost_is_waived_from_the_free_from_price(): void
    {
        $cost = new AdditionalCost(15, freeFrom: 150);

        $this->assertSame(15.0, $cost->on(149.99));
        $this->assertSame(0.0, $cost->on(150));
        $this->assertSame(0.0, $cost->on(150.01));
    }

    public function test_a_price_that_is_not_positive_has_no_cost(): void
    {
        $cost = new AdditionalCost(15);

        $this->assertSame(0.0, $cost->on(0));
        $this->assertSame(0.0, $cost->on(-1));
    }

    public function test_no_cost_by_default(): void
    {
        $this->assertSame(0.0, (new AdditionalCost)->on(100));
    }
}
