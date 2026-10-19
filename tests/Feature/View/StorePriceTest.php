<?php

namespace Tests\Feature\View;

use App\Dto\PriceCacheDto;
use App\Filament\Resources\ProductResource\Widgets\ProductUrlStats;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The price at the store, with its additional cost, under a price that differs from it.
 */
class StorePriceTest extends TestCase
{
    use RefreshDatabase;

    public function test_converted_price_shows_the_store_price(): void
    {
        $html = $this->renderStorePrice(['price' => 125.0, 'currency' => 'RON', 'store_currency' => 'EUR', 'store_price' => 25.0]);

        $this->assertSame('€25.00 at the store', $this->text($html));
        $this->assertStringContainsString('Converted to your currency', $html);
    }

    public function test_price_with_an_additional_cost_shows_the_cost(): void
    {
        $html = $this->renderStorePrice(['price' => 164.0, 'currency' => 'USD', 'store_price' => 149.0, 'additional_cost' => 15.0]);

        $this->assertSame('$149.00 + $15.00 additional cost at the store', $this->text($html));
        $this->assertStringNotContainsString('Converted to your currency', $html);
    }

    public function test_product_views_show_the_cost_under_the_total(): void
    {
        $product = $this->makeProduct(additionalCost: 15);
        $expected = '$149.00 + $15.00 additional cost at the store';

        $this->assertStringContainsString('$164.00', $this->text($this->renderPricesColumn($product)));
        $this->assertStringContainsString($expected, $this->text($this->renderPricesColumn($product)));
        $this->assertStringContainsString($expected, $this->text($this->renderProductCard($product)));
        $this->assertStringContainsString($expected, $this->text($this->renderPriceStat($product)));
    }

    public function test_product_views_show_no_store_price_without_a_cost(): void
    {
        $product = $this->makeProduct(additionalCost: null);

        $this->assertStringNotContainsString('at the store', $this->renderPricesColumn($product));
        $this->assertStringNotContainsString('at the store', $this->renderProductCard($product));
        $this->assertStringNotContainsString('at the store', $this->renderPriceStat($product));
    }

    protected function makeProduct(?float $additionalCost): Product
    {
        Store::factory()->createOne([
            'domains' => [['domain' => 'cost-store.test']],
            'settings' => ['locale_settings' => ['locale' => 'en', 'currency' => 'USD']],
            'additional_cost' => $additionalCost,
        ]);

        $user = User::factory()->createOne();
        $this->actingAs($user);

        return Product::factory()
            ->addUrlWithPrices('https://cost-store.test/item', [149])
            ->createOne(['user_id' => $user, 'notify_price' => null, 'notify_percent' => null])
            ->refresh();
    }

    protected function renderStorePrice(array $cache): string
    {
        return (string) $this->blade('<x-store-price :cache="$cache" />', ['cache' => PriceCacheDto::fromArray($cache + ['locale' => 'en'])]);
    }

    protected function renderPricesColumn(Product $product): string
    {
        return (string) $this->view('components.prices-column', ['items' => $product->price_cache]);
    }

    protected function renderProductCard(Product $product): string
    {
        return (string) $this->blade('<x-product-card :product="$product" />', ['product' => $product]);
    }

    protected function renderPriceStat(Product $product): string
    {
        return Livewire::test(ProductUrlStats::class, ['record' => $product])->html();
    }

    protected function text(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    }
}
