<?php

namespace App\Services;

use App\Dto\CurrencyConversion;
use App\Services\Helpers\CurrencyHelper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Converts store currencies into the app currency, so prices from stores that use
 * different currencies can be compared. Rates come from a Frankfurter-compatible
 * endpoint (ECB reference rates by default) and are cached for half a day.
 */
class ExchangeRateService
{
    public const string CACHE_KEY_PREFIX = 'exchange_rates.';

    /**
     * Conversion from $currency into the app currency. The identity when the currencies
     * match or no rate is available, which leaves the amount as scraped.
     */
    public static function toAppCurrency(string $currency): CurrencyConversion
    {
        $currency = strtoupper($currency);
        $appCurrency = strtoupper(CurrencyHelper::getCurrency());

        if ($currency === $appCurrency) {
            return CurrencyConversion::identity($currency);
        }

        // The endpoint quotes 1 unit of the app currency in each other currency.
        $quote = (float) (self::ratesAgainst($appCurrency)[$currency] ?? 0);

        return $quote > 0
            ? new CurrencyConversion($currency, $appCurrency, 1 / $quote)
            : CurrencyConversion::identity($currency);
    }

    /**
     * @return array<string, float|int> currency => units per 1 $base
     */
    protected static function ratesAgainst(string $base): array
    {
        return once(fn (): array => Cache::get(self::CACHE_KEY_PREFIX.$base) ?? self::fetchRates($base));
    }

    /**
     * @return array<string, float|int>
     */
    protected static function fetchRates(string $base): array
    {
        $url = config('services.exchange_rates.url');

        if (blank($url)) {
            return [];
        }

        try {
            $rates = (array) Http::timeout(10)->get($url, ['base' => $base])->throw()->json('rates', []);
        } catch (Throwable $e) {
            logger()->warning('Unable to fetch exchange rates: '.$e->getMessage(), ['base' => $base]);
            $rates = [];
        }

        // A failed lookup is retried after a few minutes instead of on every price read.
        Cache::put(self::CACHE_KEY_PREFIX.$base, $rates, $rates === [] ? now()->addMinutes(15) : now()->addHours(12));

        return $rates;
    }
}
