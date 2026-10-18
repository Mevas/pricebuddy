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

    /**
     * RON per 1 EUR quoted by the faked rates endpoint. Set it before any price is converted.
     */
    protected float $ronPerEuro = 5;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.exchange_rates.url', 'https://rates.test/latest');
        Http::fake(['rates.test/*' => fn () => Http::response(['base' => 'EUR', 'rates' => ['RON' => $this->ronPerEuro]])]);
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

    public function test_lowest_price_in_days_compares_converted_prices(): void
    {
        // 25 EUR (125 RON) is below the 30 and 29 EUR seen this week.
        $dropped = $this->makeProduct(ronPrices: [], eurPrices: [30, 29, 25]);
        // 30 EUR (150 RON) is the highest price this week.
        $risen = $this->makeProduct(ronPrices: [], eurPrices: [20, 25, 30]);

        $matching = Product::query()->lowestPriceInDays(7)->pluck('id');

        $this->assertTrue($matching->contains($dropped->id));
        $this->assertFalse($matching->contains($risen->id));
    }

    public function test_an_unchanged_converted_price_behaves_like_an_unchanged_local_price(): void
    {
        // 23.51 EUR at 5.0302 is 118.26002 RON: converted prices are rounded to cents, so an
        // unchanged price is not read as a rise or drop through float noise.
        $this->ronPerEuro = 5.0302;

        $foreign = $this->makeProduct(ronPrices: [], eurPrices: [23.51, 23.51, 23.51], attrs: ['notify_price' => 118.26, 'notify_percent' => null]);
        $local = $this->makeProduct(ronPrices: [118.26, 118.26, 118.26], eurPrices: []);

        $this->assertSame(118.26, (float) $foreign->current_price);
        $this->assertSame($local->trend, $foreign->trend);
        $this->assertTrue($foreign->is_notified_price);
        $this->assertFalse(Product::query()->lowestPriceInDays(7)->pluck('id')->contains($foreign->id));
    }

    public function test_lowest_price_in_days_sees_a_drop_within_a_day(): void
    {
        $product = $this->makeProduct(ronPrices: [], eurPrices: [30]);
        /** @var Url $url */
        $url = $product->urls->first();

        // Yesterday's 30 EUR at 06:00 dropped to 25 EUR at 18:00.
        $url->prices()->create(['price' => 25, 'unit_price' => 25, 'store_id' => $url->store_id, 'created_at' => now()->subDay()->setTime(18, 0)]);

        $this->assertEqualsWithDelta(125.0, $product->refresh()->current_price, 0.001);
        $this->assertTrue(Product::query()->lowestPriceInDays(7)->pluck('id')->contains($product->id));
    }

    public function test_store_without_a_currency_uses_the_app_currency(): void
    {
        $store = $this->makeStore('blank-store.test', 'RON');
        $store->update(['settings' => ['locale_settings' => ['locale' => 'en', 'currency' => null]]]);

        $product = Product::factory()->addUrlWithPrices('https://blank-store.test/item', [10])->createOne()->refresh();

        $this->assertSame('RON', $store->refresh()->currency);
        $this->assertSame(10.0, (float) $product->current_price);
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
        $product = $this->makeProduct(ronPrices: [149], eurPrices: [], attrs: ['notify_price' => 100, 'notify_percent' => null]);
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
        $product = $this->makeProduct(ronPrices: [], eurPrices: [30], attrs: ['notify_price' => null, 'notify_percent' => 10]);

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
