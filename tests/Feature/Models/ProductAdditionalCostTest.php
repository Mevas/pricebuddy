<?php

namespace Tests\Feature\Models;

use App\Dto\PriceCacheDto;
use App\Enums\StockStatus;
use App\Jobs\RegeneratePriceCache;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\PriceAlertNotification;
use App\Services\ExchangeRateService;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A store's additional cost, such as shipping, is part of what a product costs there,
 * so it is added to the store's prices before they are compared.
 */
class ProductAdditionalCostTest extends TestCase
{
    use RefreshDatabase;

    const RON_URL = 'https://ron-store.test/item';

    const EUR_URL = 'https://eur-store.test/item';

    /**
     * RON per 1 EUR quoted by the faked rates endpoint.
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
        $this->useRate(5);
    }

    public function test_the_cost_is_added_in_the_store_currency_before_prices_are_compared(): void
    {
        $this->store('eur-store.test')->update(['additional_cost' => 8.99]);

        // 25 EUR is 125 RON, but with 8.99 EUR shipping it is 169.95 RON.
        $product = $this->makeProduct(ronPrices: [149], eurPrices: [25]);

        $this->assertSame('ron-store.test', $product->getPriceCache()->first()->getStoreName());
        $this->assertEqualsWithDelta(149.0, $product->current_price, 0.001);
        $this->assertEqualsWithDelta(169.95, $product->getPriceCacheAggregate('max'), 0.001);

        $eur = $this->cacheFor($product, 'eur-store.test');
        $this->assertSame("RON\u{a0}169.95", $eur->getUnitPriceFormatted());
        $this->assertSame('€25.00', $eur->getStorePriceFormatted());
        $this->assertSame('€8.99', $eur->getAdditionalCostFormatted());
        $this->assertTrue($eur->differsFromStorePrice());
        $this->assertFalse($this->cacheFor($product, 'ron-store.test')->differsFromStorePrice());
    }

    public function test_a_local_store_cost_is_shown_next_to_the_store_price(): void
    {
        $this->store('ron-store.test')->update(['additional_cost' => 15]);

        $product = $this->makeProduct(ronPrices: [149], eurPrices: []);

        $ron = $this->cacheFor($product, 'ron-store.test');
        $this->assertEqualsWithDelta(164.0, $product->current_price, 0.001);
        $this->assertFalse($ron->isConverted());
        $this->assertTrue($ron->differsFromStorePrice());
        $this->assertSame("RON\u{a0}149.00", $ron->getStorePriceFormatted());
        $this->assertSame("RON\u{a0}15.00", $ron->getAdditionalCostFormatted());
    }

    public function test_the_cost_is_waived_from_the_free_from_price(): void
    {
        $this->store('ron-store.test')->update(['additional_cost' => 15, 'additional_cost_free_from' => 150]);

        $product = $this->makeProduct(ronPrices: [160, 150, 140], eurPrices: []);

        $url = $product->urls->firstWhere('url', self::RON_URL);
        // 160 and 150 ship free; 140 pays 15.
        $this->assertSame([160.0, 150.0, 155.0], $product->getPriceHistory()[$url->id]->values()->map(fn ($p) => (float) $p)->all());
        $this->assertEqualsWithDelta(155.0, $product->current_price, 0.001);
        $this->assertSame("RON\u{a0}15.00", $this->cacheFor($product, 'ron-store.test')->getAdditionalCostFormatted());
    }

    public function test_a_link_cost_replaces_the_store_cost(): void
    {
        $this->store('ron-store.test')->update(['additional_cost' => 15, 'additional_cost_free_from' => 100]);

        $product = $this->makeProduct(ronPrices: [160], eurPrices: []);
        $url = $product->urls->firstWhere('url', self::RON_URL);

        // The link's own cost applies whatever the price; the store's free-from does not.
        $url->update(['additional_cost' => 20]);
        $this->assertEqualsWithDelta(180.0, $product->refresh()->current_price, 0.001);

        // A cost of zero is a cost too: this link ships free.
        $url->update(['additional_cost' => 0]);
        $this->assertEqualsWithDelta(160.0, $product->refresh()->current_price, 0.001);

        // Without its own cost the link uses the store's again.
        $this->store('ron-store.test')->update(['additional_cost_free_from' => null]);
        $url->update(['additional_cost' => null]);
        $this->assertEqualsWithDelta(175.0, $product->refresh()->current_price, 0.001);
    }

    public function test_the_cost_is_paid_once_per_listing_not_per_unit(): void
    {
        $this->store('ron-store.test')->update(['additional_cost' => 6]);

        // A pack of 3 at 30 costs 36 delivered: 12 a unit.
        $product = Product::factory()
            ->addUrlWithPrices(self::RON_URL, [30], priceFactor: 3)
            ->createOne(['user_id' => User::factory()])
            ->refresh();

        $ron = $this->cacheFor($product, 'ron-store.test');
        $this->assertEqualsWithDelta(12.0, $ron->getUnitPrice(), 0.001);
        $this->assertEqualsWithDelta(36.0, $ron->getPrice(), 0.001);
        $this->assertEqualsWithDelta(12.0, $product->current_price, 0.001);
    }

    public function test_history_and_insights_include_the_cost(): void
    {
        $this->store('eur-store.test')->update(['additional_cost' => 5]);

        $product = $this->makeProduct(ronPrices: [], eurPrices: [25]);
        $url = $product->urls->firstWhere('url', self::EUR_URL);

        // (25 + 5) EUR is 150 RON.
        $this->assertEqualsWithDelta(150.0, $product->getPriceHistory()[$url->id]->last(), 0.001);
        $this->assertEqualsWithDelta(150.0, $product->getPriceHistory('unit_price')[$url->id]->last(), 0.001);
        $this->assertEqualsWithDelta(150.0, $product->buildInsightsCache()['bestPrice'], 0.001);
    }

    public function test_a_price_of_zero_has_no_cost(): void
    {
        $this->store('ron-store.test')->update(['additional_cost' => 15]);

        $product = Product::factory()
            ->addUrlWithPrices(self::RON_URL, [0], availability: StockStatus::OutOfStock)
            ->createOne(['user_id' => User::factory()])
            ->refresh();

        $ron = $this->cacheFor($product, 'ron-store.test');
        $this->assertEqualsWithDelta(0.0, $ron->getUnitPrice(), 0.001);
        $this->assertFalse($ron->hasAdditionalCost());
    }

    public function test_changing_a_store_cost_rebuilds_its_product_caches(): void
    {
        $product = $this->makeProduct(ronPrices: [149], eurPrices: [25]);
        $other = Product::factory()->addUrlWithPrices('https://other-store.test/item', [100])->createOne(['user_id' => User::factory()]);

        Queue::fake();
        $this->store('ron-store.test')->update(['additional_cost' => 10]);

        Queue::assertPushed(RegeneratePriceCache::class, fn (RegeneratePriceCache $job) => $job->productIds === [$product->id]);
        $this->assertNotContains($other->id, Queue::pushed(RegeneratePriceCache::class)->flatMap->productIds);
    }

    public function test_a_store_cost_change_is_applied_to_cached_prices(): void
    {
        $product = $this->makeProduct(ronPrices: [149], eurPrices: []);

        $this->store('ron-store.test')->update(['additional_cost' => 10]);

        $this->assertEqualsWithDelta(159.0, $product->refresh()->current_price, 0.001);
    }

    public function test_changing_a_link_cost_rebuilds_its_product_cache(): void
    {
        $product = $this->makeProduct(ronPrices: [149], eurPrices: [25]);
        $url = $product->urls->firstWhere('url', self::RON_URL);

        Queue::fake();
        $url->update(['additional_cost' => 10]);
        Queue::assertPushed(RegeneratePriceCache::class, fn (RegeneratePriceCache $job) => $job->productIds === [$product->id]);

        Queue::fake();
        $url->update(['price_factor' => 1]);
        Queue::assertNotPushed(RegeneratePriceCache::class);
    }

    public function test_other_store_changes_leave_product_caches_alone(): void
    {
        $this->makeProduct(ronPrices: [149], eurPrices: [25]);

        Queue::fake();
        $this->store('ron-store.test')->update(['name' => 'Renamed', 'additional_cost' => null]);

        Queue::assertNotPushed(RegeneratePriceCache::class);
    }

    public function test_lowest_price_in_days_includes_the_cost(): void
    {
        $this->store('ron-store.test')->update(['additional_cost' => 15, 'additional_cost_free_from' => 150]);

        // The price fell but no longer ships free, so the total is unchanged at 160.
        $unchanged = $this->makeProduct(ronPrices: [160, 160, 145], eurPrices: []);
        // The price rose past the free-from, so the total fell from 164 to 151.
        $dropped = $this->makeProduct(ronPrices: [149, 149, 151], eurPrices: []);

        $matching = Product::query()->lowestPriceInDays(7)->pluck('id');

        $this->assertFalse($matching->contains($unchanged->id));
        $this->assertTrue($matching->contains($dropped->id));
    }

    public function test_an_unchanged_price_with_a_cost_is_not_a_drop(): void
    {
        // Totals that are not exact in binary must still equal their own history row, both
        // in a local currency and when converted at a rate that does not terminate.
        $this->store('ron-store.test')->update(['additional_cost' => 8.99]);
        $this->store('eur-store.test')->update(['additional_cost' => 4.49]);
        $this->useRate(5.0302);

        $local = $this->makeProduct(ronPrices: [45.319, 45.319, 45.319], eurPrices: []);
        $foreign = $this->makeProduct(ronPrices: [], eurPrices: [23.51, 23.51, 23.51]);
        $foreignPack = Product::factory()
            ->addUrlWithPrices(self::EUR_URL, [23.51, 23.51, 23.51], priceFactor: 7)
            ->createOne(['user_id' => User::factory()]);

        $matching = Product::query()->lowestPriceInDays(7)->pluck('id');

        $this->assertFalse($matching->contains($local->id));
        $this->assertFalse($matching->contains($foreign->id));
        $this->assertFalse($matching->contains($foreignPack->id));
    }

    public function test_lowest_price_in_days_uses_the_link_cost(): void
    {
        $this->store('ron-store.test')->update(['additional_cost' => 15]);
        $product = $this->makeProduct(ronPrices: [100, 100, 100], eurPrices: []);

        $product->urls->firstWhere('url', self::RON_URL)->update(['additional_cost' => 0]);

        $this->assertFalse(Product::query()->lowestPriceInDays(7)->pluck('id')->contains($product->id));
    }

    public function test_notification_target_includes_the_cost(): void
    {
        Notification::fake();
        $this->store('ron-store.test')->update(['additional_cost' => 15]);

        $product = $this->makeProduct(ronPrices: [200], eurPrices: [], attrs: ['notify_price' => 150, 'notify_percent' => null]);
        /** @var Url $url */
        $url = $product->urls->firstWhere('url', self::RON_URL);

        // 140 is below the target, but 155 with shipping is not.
        $url->prices()->create(['price' => 140, 'unit_price' => 140, 'store_id' => $url->store_id]);
        Notification::assertNothingSent();

        $this->travel(1)->minute();
        $url->prices()->create(['price' => 130, 'unit_price' => 130, 'store_id' => $url->store_id]);
        Notification::assertSentTo($product->user, PriceAlertNotification::class);
        $this->assertSame("RON\u{a0}130.00 + RON\u{a0}15.00 additional cost", $url->refresh()->latest_price_formatted);
    }

    public function test_notification_percentage_includes_the_cost(): void
    {
        $this->store('ron-store.test')->update(['additional_cost' => 20]);

        $product = $this->makeProduct(ronPrices: [100], eurPrices: [], attrs: ['notify_price' => null, 'notify_percent' => 10]);

        // The first price is 120 delivered; 10% off is 108.
        $this->assertFalse($product->shouldNotifyOnPrice(110));
        $this->assertTrue($product->shouldNotifyOnPrice(108));
    }

    /**
     * Store the rates as buddy:update-exchange-rates does.
     */
    protected function useRate(float $ronPerEuro): void
    {
        $this->ronPerEuro = $ronPerEuro;
        app(ExchangeRateService::class)->refresh();
    }

    protected function makeStore(string $domain, string $currency): Store
    {
        return Store::factory()->createOne([
            'name' => $domain,
            'domains' => [['domain' => $domain]],
            'settings' => ['locale_settings' => ['locale' => 'en', 'currency' => $currency]],
        ]);
    }

    protected function store(string $name): Store
    {
        return Store::query()->where('name', $name)->firstOrFail();
    }

    protected function cacheFor(Product $product, string $storeName): PriceCacheDto
    {
        return $product->getPriceCache()->firstOrFail(fn (PriceCacheDto $cache) => $cache->getStoreName() === $storeName);
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
