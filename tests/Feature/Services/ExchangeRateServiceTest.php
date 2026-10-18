<?php

namespace Tests\Feature\Services;

use App\Dto\CurrencyConversion;
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

    public function test_same_currency_is_the_identity_without_a_lookup(): void
    {
        Http::fake();

        $conversion = ExchangeRateService::toAppCurrency('ron');

        $this->assertFalse($conversion->isConverted());
        $this->assertSame(10.0, $conversion->convert(10));
        Http::assertNothingSent();
    }

    public function test_converts_into_the_app_currency_using_euro_quotes(): void
    {
        $this->fakeRates(['RON' => 5]);

        $conversion = ExchangeRateService::toAppCurrency('EUR');

        $this->assertSame('EUR', $conversion->from);
        $this->assertSame('RON', $conversion->to);
        $this->assertSame(50.0, $conversion->convert(10));
        Http::assertSent(fn ($request) => $request['base'] === 'EUR');
    }

    public function test_converts_between_two_non_euro_currencies(): void
    {
        // 1 EUR is 5 RON or 1.25 USD, so 1 USD is 4 RON.
        $this->fakeRates(['RON' => 5, 'USD' => 1.25]);

        $this->assertSame(40.0, ExchangeRateService::toAppCurrency('USD')->convert(10));
    }

    public function test_converted_amounts_are_rounded_to_cents(): void
    {
        $this->fakeRates(['RON' => 5.0302]);

        $this->assertSame(118.26, ExchangeRateService::toAppCurrency('EUR')->convert(23.51));
    }

    public function test_changing_the_app_currency_needs_no_new_lookup(): void
    {
        $this->fakeRates(['RON' => 5, 'USD' => 1.25]);

        ExchangeRateService::toAppCurrency('EUR');
        SettingsHelper::setSetting('default_locale_settings', ['locale' => 'en', 'currency' => 'USD']);

        $this->assertSame(12.5, $this->conversionInNewJob()->convert(10));
        Http::assertSentCount(1);
    }

    public function test_rates_are_reused_until_they_are_twelve_hours_old(): void
    {
        $this->fakeRates(['RON' => 5], ['RON' => 4]);

        $this->assertRate(5.0);
        $this->travel(11)->hours();
        $this->assertRate(5.0);
        Http::assertSentCount(1);

        $this->travel(2)->hours();
        $this->assertRate(4.0);
        Http::assertSentCount(2);
    }

    public function test_a_failed_refresh_keeps_the_last_rates_and_retries_later(): void
    {
        $this->fakeRates(['RON' => 5], null, ['RON' => 4]);

        $this->assertRate(5.0);
        $this->travel(13)->hours();
        $this->assertRate(5.0);
        Http::assertSentCount(2);

        // A failure is not retried on every read.
        $this->travel(10)->minutes();
        $this->assertRate(5.0);
        Http::assertSentCount(2);

        $this->travel(6)->minutes();
        $this->assertRate(4.0);
        Http::assertSentCount(3);
    }

    public function test_without_any_rates_the_conversion_is_the_identity(): void
    {
        $this->fakeRates(null);

        $conversion = $this->conversionInNewJob();

        $this->assertFalse($conversion->isConverted());
        $this->assertSame('EUR', $conversion->to);
    }

    public function test_unknown_currency_is_the_identity_in_the_store_currency(): void
    {
        $this->fakeRates(['RON' => 5]);

        $conversion = ExchangeRateService::toAppCurrency('XYZ');

        $this->assertSame('XYZ', $conversion->to);
        $this->assertSame(10.0, $conversion->convert(10));
    }

    public function test_conversion_is_disabled_without_a_rates_url(): void
    {
        config()->set('services.exchange_rates.url', '');
        Http::fake();

        $this->assertFalse(ExchangeRateService::toAppCurrency('EUR')->isConverted());
        Http::assertNothingSent();
    }

    /**
     * Each argument is one response in order: an array of rates, or null for a failure.
     */
    protected function fakeRates(?array ...$responses): void
    {
        $sequence = Http::sequence();

        foreach ($responses as $rates) {
            $rates === null
                ? $sequence->push('down', 503)
                : $sequence->push(['base' => 'EUR', 'rates' => $rates]);
        }

        Http::fake([self::RATES_URL.'*' => $sequence]);
    }

    protected function assertRate(float $expected): void
    {
        $this->assertEqualsWithDelta($expected, $this->conversionInNewJob()->rate, 0.0001);
    }

    /**
     * Rates are memoised per request or job, so start a new one as a queue worker does.
     */
    protected function conversionInNewJob(): CurrencyConversion
    {
        $this->app->forgetScopedInstances();

        return ExchangeRateService::toAppCurrency('EUR');
    }
}
