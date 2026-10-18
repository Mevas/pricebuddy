<?php

namespace Tests\Feature\Services;

use App\Services\ExchangeRateService;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExchangeRateServiceTest extends TestCase
{
    use RefreshDatabase;

    const RATES_URL = 'https://rates.test/latest';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.exchange_rates.url', self::RATES_URL);
        SettingsHelper::setSetting('default_locale_settings', ['locale' => 'en', 'currency' => 'RON']);
    }

    public function test_same_currency_is_the_identity(): void
    {
        $this->refreshWith(['RON' => 5]);

        $conversion = $this->rates()->toAppCurrency('ron');

        $this->assertFalse($conversion->isConverted());
        $this->assertSame(10.0, $conversion->convert(10));
    }

    public function test_converts_into_the_app_currency_using_euro_quotes(): void
    {
        $this->refreshWith(['RON' => 5]);

        $conversion = $this->rates()->toAppCurrency('EUR');

        $this->assertSame('EUR', $conversion->from);
        $this->assertSame('RON', $conversion->to);
        $this->assertSame(50.0, $conversion->convert(10));
        Http::assertSent(fn ($request) => $request['base'] === 'EUR');
    }

    public function test_converts_between_two_non_euro_currencies(): void
    {
        // 1 EUR is 5 RON or 1.25 USD, so 1 USD is 4 RON.
        $this->refreshWith(['RON' => 5, 'USD' => 1.25]);

        $this->assertSame(40.0, $this->rates()->toAppCurrency('USD')->convert(10));
    }

    public function test_converted_amounts_are_rounded_to_cents_but_unit_prices_are_not(): void
    {
        $this->refreshWith(['RON' => 5.0302]);

        $conversion = $this->rates()->toAppCurrency('EUR');

        $this->assertSame(118.26, $conversion->convert(23.51));
        $this->assertEqualsWithDelta(0.03924, $conversion->convertPerUnit(3.90, 500), 0.000001);
    }

    public function test_half_cents_are_rounded_up_like_the_database_does(): void
    {
        // As floats these products sit just below the half cent and would round down.
        $this->refreshWith(['RON' => 4.9758, 'JPY' => 162.45]);

        $this->assertSame(124.4, $this->rates()->toAppCurrency('EUR')->convert(25.00));

        SettingsHelper::setSetting('default_locale_settings', ['locale' => 'en', 'currency' => 'JPY']);
        $this->assertSame(48.74, $this->rates()->toAppCurrency('EUR')->convert(0.30));
    }

    public function test_rates_are_read_from_storage_without_a_lookup(): void
    {
        $this->refreshWith(['RON' => 5, 'USD' => 1.25]);
        $this->app->forgetScopedInstances();
        SettingsHelper::setSetting('default_locale_settings', ['locale' => 'en', 'currency' => 'USD']);

        $this->assertSame(12.5, $this->rates()->toAppCurrency('EUR')->convert(10));
        Http::assertSentCount(1);
    }

    public function test_refresh_reports_whether_the_rates_changed(): void
    {
        $this->fakeResponses(['RON' => 5], ['RON' => 5], ['RON' => 4]);

        $this->assertTrue($this->rates()->refresh());
        $this->assertFalse($this->rates()->refresh());
        $this->assertTrue($this->rates()->refresh());
        $this->assertSame(40.0, $this->rates()->toAppCurrency('EUR')->convert(10));
    }

    public function test_a_failed_refresh_keeps_the_stored_rates(): void
    {
        $this->fakeResponses(['RON' => 5], null);

        $this->rates()->refresh();
        $this->assertFalse($this->rates()->refresh());

        $this->app->forgetScopedInstances();
        $this->assertSame(50.0, $this->rates()->toAppCurrency('EUR')->convert(10));
    }

    public function test_without_stored_rates_the_conversion_is_the_identity(): void
    {
        $conversion = $this->rates()->toAppCurrency('EUR');

        $this->assertFalse($conversion->isConverted());
        $this->assertSame('EUR', $conversion->to);
    }

    public function test_unknown_currency_is_the_identity_in_the_store_currency(): void
    {
        $this->refreshWith(['RON' => 5]);

        $conversion = $this->rates()->toAppCurrency('XYZ');

        $this->assertSame('XYZ', $conversion->to);
        $this->assertSame(10.0, $conversion->convert(10));
    }

    public function test_conversion_is_disabled_without_a_rates_url(): void
    {
        $this->refreshWith(['RON' => 5]);
        $this->app->forgetScopedInstances();
        config()->set('services.exchange_rates.url', '');

        $this->assertFalse($this->rates()->refresh());
        $this->assertFalse($this->rates()->toAppCurrency('EUR')->isConverted());
        Http::assertSentCount(1);
    }

    protected function rates(): ExchangeRateService
    {
        return app(ExchangeRateService::class);
    }

    protected function refreshWith(array $rates): void
    {
        $this->fakeResponses($rates);
        $this->rates()->refresh();
    }

    /**
     * Each argument is one response in order: an array of rates, or null for a failure.
     */
    protected function fakeResponses(?array ...$responses): void
    {
        $sequence = Http::sequence();

        foreach ($responses as $rates) {
            $rates === null
                ? $sequence->push('down', 503)
                : $sequence->push(['base' => 'EUR', 'rates' => $rates]);
        }

        Http::fake([self::RATES_URL.'*' => $sequence]);
    }
}
