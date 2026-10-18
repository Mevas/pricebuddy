<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RegeneratePriceCache;
use App\Jobs\UpdateExchangeRates;
use App\Models\Product;
use App\Models\Store;
use App\Services\ExchangeRateService;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UpdateExchangeRatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.exchange_rates.url', 'https://rates.test/latest');
        SettingsHelper::setSetting('default_locale_settings', ['locale' => 'en', 'currency' => 'RON']);
        Queue::fake();
    }

    public function test_does_not_fetch_rates_when_every_store_uses_the_app_currency(): void
    {
        Http::fake();
        $this->makeStore('ron-store.test', 'RON');

        $this->runJob(new UpdateExchangeRates([7]));

        Http::assertNothingSent();
        Queue::assertPushed(RegeneratePriceCache::class, fn (RegeneratePriceCache $job) => $job->productIds === [7]);
    }

    public function test_changed_rates_recalculate_products_sold_in_another_currency(): void
    {
        $this->fakeRates();
        $this->makeStore('ron-store.test', 'RON');
        $this->makeStore('eur-store.test', 'EUR');
        $foreign = Product::factory()->addUrlWithPrices('https://eur-store.test/item', [10])->createOne();
        $local = Product::factory()->addUrlWithPrices('https://ron-store.test/item', [10])->createOne();

        $this->runJob(new UpdateExchangeRates([$local->id]));

        Queue::assertPushed(RegeneratePriceCache::class, fn (RegeneratePriceCache $job) => $job->productIds === [$local->id, $foreign->id]);
    }

    public function test_unchanged_rates_recalculate_only_the_given_products(): void
    {
        $this->fakeRates();
        $this->makeStore('eur-store.test', 'EUR');
        $this->runJob(new UpdateExchangeRates);
        Queue::fake();

        $this->runJob(new UpdateExchangeRates);
        Queue::assertNothingPushed();

        $this->runJob(new UpdateExchangeRates([3]));
        Queue::assertPushed(RegeneratePriceCache::class, fn (RegeneratePriceCache $job) => $job->productIds === [3]);
    }

    public function test_every_product_is_recalculated_when_asked(): void
    {
        $this->fakeRates();
        $this->makeStore('eur-store.test', 'EUR');

        $this->runJob(new UpdateExchangeRates(null));

        Queue::assertPushed(RegeneratePriceCache::class, fn (RegeneratePriceCache $job) => $job->productIds === null);
    }

    protected function runJob(UpdateExchangeRates $job): void
    {
        $job->handle(app(ExchangeRateService::class));
    }

    protected function fakeRates(): void
    {
        Http::fake(['rates.test/*' => Http::response(['base' => 'EUR', 'rates' => ['RON' => 5]])]);
    }

    protected function makeStore(string $domain, string $currency): Store
    {
        return Store::factory()->createOne([
            'name' => $domain,
            'domains' => [['domain' => $domain]],
            'settings' => ['locale_settings' => ['locale' => 'en', 'currency' => $currency]],
        ]);
    }
}
