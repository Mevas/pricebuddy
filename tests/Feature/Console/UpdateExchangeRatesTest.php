<?php

namespace Tests\Feature\Console;

use App\Console\Commands\UpdateExchangeRates;
use App\Jobs\RegeneratePriceCache;
use App\Models\Product;
use App\Models\Store;
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

        $this->artisan(UpdateExchangeRates::COMMAND)->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_changed_rates_recalculate_products_sold_in_another_currency(): void
    {
        Http::fake(['rates.test/*' => Http::response(['base' => 'EUR', 'rates' => ['RON' => 5]])]);
        $this->makeStore('ron-store.test', 'RON');
        $this->makeStore('eur-store.test', 'EUR');
        $foreign = Product::factory()->addUrlWithPrices('https://eur-store.test/item', [10])->createOne();
        Product::factory()->addUrlWithPrices('https://ron-store.test/item', [10])->createOne();

        $this->artisan(UpdateExchangeRates::COMMAND)->assertExitCode(0);

        Queue::assertPushed(RegeneratePriceCache::class, fn (RegeneratePriceCache $job) => $job->productIds === [$foreign->id]);
    }

    public function test_unchanged_rates_recalculate_nothing(): void
    {
        Http::fake(['rates.test/*' => Http::response(['base' => 'EUR', 'rates' => ['RON' => 5]])]);
        $this->makeStore('eur-store.test', 'EUR');

        $this->artisan(UpdateExchangeRates::COMMAND);
        Queue::fake();
        $this->artisan(UpdateExchangeRates::COMMAND)->assertExitCode(0);

        Queue::assertNotPushed(RegeneratePriceCache::class);
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
