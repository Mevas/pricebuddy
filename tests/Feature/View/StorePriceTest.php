<?php

namespace Tests\Feature\View;

use App\Dto\PriceCacheDto;
use Tests\TestCase;

class StorePriceTest extends TestCase
{
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

    protected function renderStorePrice(array $cache): string
    {
        return (string) $this->blade('<x-store-price :cache="$cache" />', ['cache' => PriceCacheDto::fromArray($cache + ['locale' => 'en'])]);
    }

    protected function text(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags($html)));
    }
}
