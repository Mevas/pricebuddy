<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProductResource\Widgets\PriceHistoryChart;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PriceHistoryChartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_chart_includes_a_best_price_dataset(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://example-a.com/p', [60, 55, 50])
            ->create();

        Livewire::test(PriceHistoryChart::class, ['record' => $product])
            ->assertSee('Best price');
    }

    public function test_chart_includes_target_line_when_notify_price_is_set(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://example-a.com/p', [60, 55, 50])
            ->create(['notify_price' => 45.00]);

        Livewire::test(PriceHistoryChart::class, ['record' => $product])
            ->assertSee('Your target');
    }

    public function test_unit_prices_of_a_pack_have_no_target_line(): void
    {
        // The target is a retail price, so it only belongs on the retail prices of a 500-pack.
        $product = Product::factory()
            ->addUrlWithPrices('https://example-a.com/p', [19.80, 19.50], priceFactor: 500)
            ->create(['notify_price' => 18.00]);

        Livewire::test(PriceHistoryChart::class, ['record' => $product])
            ->assertDontSee('Your target')
            ->set('filter', 'retail_price')
            ->assertSee('Your target');
    }
}
