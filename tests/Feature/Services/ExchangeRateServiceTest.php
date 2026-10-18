<?php

namespace Tests\Feature\Services;

use App\Services\ExchangeRateService;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Once;
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

    public function test_converts_into_the_app_currency_using_the_quoted_rate(): void
    {
        Http::fake([self::RATES_URL.'*' => Http::response(['base' => 'RON', 'rates' => ['EUR' => 0.2]])]);

        $conversion = ExchangeRateService::toAppCurrency('EUR');

        $this->assertSame('EUR', $conversion->from);
        $this->assertSame('RON', $conversion->to);
        $this->assertEqualsWithDelta(50.0, $conversion->convert(10), 0.0001);
        Http::assertSent(fn ($request) => $request['base'] === 'RON');
    }

    public function test_rates_are_cached_between_requests(): void
    {
        Http::fake([self::RATES_URL.'*' => Http::response(['rates' => ['EUR' => 0.2]])]);

        ExchangeRateService::toAppCurrency('EUR');
        Once::flush();
        ExchangeRateService::toAppCurrency('EUR');

        Http::assertSentCount(1);
    }

    public function test_unknown_currency_is_the_identity_in_the_store_currency(): void
    {
        Http::fake([self::RATES_URL.'*' => Http::response(['rates' => ['EUR' => 0.2]])]);

        $conversion = ExchangeRateService::toAppCurrency('XYZ');

        $this->assertSame('XYZ', $conversion->to);
        $this->assertSame(10.0, $conversion->convert(10));
    }

    public function test_failed_lookup_is_the_identity_and_is_not_retried_immediately(): void
    {
        Http::fake([self::RATES_URL.'*' => Http::response('down', 503)]);

        $this->assertFalse(ExchangeRateService::toAppCurrency('EUR')->isConverted());
        Once::flush();
        $this->assertFalse(ExchangeRateService::toAppCurrency('EUR')->isConverted());

        Http::assertSentCount(1);
    }

    public function test_conversion_is_disabled_without_a_rates_url(): void
    {
        config()->set('services.exchange_rates.url', '');
        Http::fake();

        $this->assertFalse(ExchangeRateService::toAppCurrency('EUR')->isConverted());
        Http::assertNothingSent();
    }
}
