<?php

namespace Tests\Feature\Models;

use App\Dto\PriceCacheDto;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\PriceAlertNotification;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A product tracked at stores that price in different currencies is compared in the
 * app currency.
 */
class ProductCurrencyConversionTest extends TestCase
{
    use RefreshDatabase;

    const RON_URL = 'https://ron-store.test/item';

    const EUR_URL = 'https://eur-store.test/item';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.exchange_rates.url', 'https://rates.test/latest');
        Http::fake(['rates.test/*' => Http::response(['base' => 'RON', 'rates' => ['EUR' => 0.2]])]);
        SettingsHelper::setSetting('default_locale_settings', ['locale' => 'en', 'currency' => 'RON']);

        $this->makeStore('ron-store.test', 'RON');
        $this->makeStore('eur-store.test', 'EUR');
    }

    public function test_prices_from_another_currency_are_converted_into_the_app_currency(): void
    {
        $product = $this->makeProduct(ronPrices: [150, 149], eurPrices: [26, 25]);

        // 25 EUR is 125 RON, so the EUR store is the cheapest.
        $cheapest = $product->getPriceCache()->first();
        $this->assertSame('eur-store.test', $cheapest->getStoreName());
        $this->assertEqualsWithDelta(125.0, $cheapest->getPrice(), 0.001);
        $this->assertEqualsWithDelta(125.0, $product->current_price, 0.001);

        $this->assertEqualsWithDelta(125.0, $product->getPriceCacheAggregate('min'), 0.001);
        $this->assertEqualsWithDelta(150.0, $product->getPriceCacheAggregate('max'), 0.001);
        $this->assertSame("RON\u{a0}125.00", $product->price_aggregates['min']);
    }

    public function test_converted_price_cache_keeps_the_store_price(): void
    {
        $product = $this->makeProduct(ronPrices: [149], eurPrices: [25]);

        /** @var PriceCacheDto $eur */
        $eur = $product->getPriceCache()->firstWhere(fn (PriceCacheDto $c) => $c->getStoreName() === 'eur-store.test');
        /** @var PriceCacheDto $ron */
        $ron = $product->getPriceCache()->firstWhere(fn (PriceCacheDto $c) => $c->getStoreName() === 'ron-store.test');

        $this->assertTrue($eur->isConverted());
        $this->assertSame("RON\u{a0}125.00", $eur->getUnitPriceFormatted());
        $this->assertSame('€25.00', $eur->getStorePriceFormatted());
        $this->assertFalse($ron->isConverted());
        $this->assertSame("RON\u{a0}149.00", $ron->getUnitPriceFormatted());
    }

    public function test_price_history_is_converted_for_insights(): void
    {
        $product = $this->makeProduct(ronPrices: [149], eurPrices: [25]);

        $eurUrl = $product->urls->firstWhere('url', self::EUR_URL);

        $this->assertEqualsWithDelta(125.0, $product->getPriceHistory()[$eurUrl->id]->last(), 0.001);
        $this->assertEqualsWithDelta(125.0, $product->getPriceHistory('unit_price')[$eurUrl->id]->last(), 0.001);
        $this->assertEqualsWithDelta(125.0, $product->buildInsightsCache()['bestPrice'], 0.001);
    }

    public function test_without_a_rate_prices_keep_their_store_currency(): void
    {
        config()->set('services.exchange_rates.url', '');

        $product = $this->makeProduct(ronPrices: [149], eurPrices: [25]);

        $eur = $product->getPriceCache()->firstWhere(fn (PriceCacheDto $c) => $c->getStoreName() === 'eur-store.test');

        $this->assertFalse($eur->isConverted());
        $this->assertSame('€25.00', $eur->getUnitPriceFormatted());
    }

    public function test_changing_a_store_currency_rebuilds_its_product_caches(): void
    {
        $product = $this->makeProduct(ronPrices: [149], eurPrices: [25]);

        $store = Store::query()->where('name', 'ron-store.test')->firstOrFail();
        $store->update(['settings' => ['locale_settings' => ['locale' => 'en', 'currency' => 'EUR']]]);

        // 149 EUR is now 745 RON, so the other store is the cheapest.
        $product->refresh();
        $this->assertEqualsWithDelta(125.0, $product->current_price, 0.001);
        $this->assertEqualsWithDelta(745.0, $product->getPriceCacheAggregate('max'), 0.001);
    }

    public function test_other_store_setting_changes_leave_product_caches_alone(): void
    {
        $product = $this->makeProduct(ronPrices: [149], eurPrices: [25]);
        $product->forceFill(['price_cache' => []])->saveQuietly();

        $store = Store::query()->where('name', 'ron-store.test')->firstOrFail();
        $store->markAiHealFailed();

        $this->assertSame([], $product->refresh()->price_cache);
    }

    public function test_notification_target_is_compared_in_the_app_currency(): void
    {
        Notification::fake();

        // 25 EUR is 125 RON: above a 100 RON target even though 25 is below it.
        $product = $this->makeProduct(ronPrices: [149], eurPrices: [], attrs: ['notify_price' => 100]);
        /** @var Url $eurUrl */
        $eurUrl = $product->urls()->create(['url' => self::EUR_URL, 'store_id' => Store::query()->where('name', 'eur-store.test')->value('id')]);

        // Creating a price fires PriceCreatedEvent, which decides whether to notify.
        $eurUrl->prices()->create(['price' => 25, 'unit_price' => 25, 'store_id' => $eurUrl->store_id]);
        Notification::assertNothingSent();

        $eurUrl->prices()->create(['price' => 19, 'unit_price' => 19, 'store_id' => $eurUrl->store_id]);
        Notification::assertSentTo($product->user, PriceAlertNotification::class);
    }

    public function test_notification_percentage_is_measured_from_the_converted_first_price(): void
    {
        $product = $this->makeProduct(ronPrices: [], eurPrices: [30], attrs: ['notify_percent' => 10]);

        // The first price is 30 EUR, 150 RON; 10% off is 135 RON.
        $this->assertFalse($product->shouldNotifyOnPrice(140));
        $this->assertTrue($product->shouldNotifyOnPrice(135));
    }

    protected function makeStore(string $domain, string $currency): Store
    {
        return Store::factory()->createOne([
            'name' => $domain,
            'domains' => [['domain' => $domain]],
            'settings' => ['locale_settings' => ['locale' => 'en', 'currency' => $currency]],
        ]);
    }

    protected function makeProduct(array $ronPrices, array $eurPrices, array $attrs = []): Product
    {
        $factory = Product::factory();

        if ($ronPrices !== []) {
            $factory = $factory->addUrlWithPrices(self::RON_URL, $ronPrices);
        }

        if ($eurPrices !== []) {
            $factory = $factory->addUrlWithPrices(self::EUR_URL, $eurPrices);
        }

        return $factory->createOne(array_merge(['user_id' => User::factory()], $attrs))->refresh();
    }
}
