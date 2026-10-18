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
 * endpoint (ECB reference rates by default).
 */
class ExchangeRateService
{
    public const string CACHE_KEY_PREFIX = 'exchange_rates.';

    /**
     * Conversion from $currency into the app currency. The identity when the currencies
     * match or no rate has ever been fetched, which leaves the amount as scraped.
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
     * The last rates fetched against $base, refreshed every 12 hours. A failed refresh
     * keeps the previous rates and is retried after a few minutes.
     *
     * @return array<string, float|int> currency => units per 1 $base
     */
    protected static function ratesAgainst(string $base): array
    {
        $url = config('services.exchange_rates.url');

        if (blank($url)) {
            return [];
        }

        // Memoised per request or queued job, so a long-running worker still sees refreshes.
        $cache = Cache::memo();
        $key = self::CACHE_KEY_PREFIX.$base;

        /** @var array{rates: array<string, float|int>, refresh_at: int}|null $stored */
        $stored = $cache->get($key);

        if ($stored && $stored['refresh_at'] > now()->getTimestamp()) {
            return $stored['rates'];
        }

        $fetched = self::fetchRates($url, $base);
        $rates = $fetched ?? $stored['rates'] ?? [];

        $cache->forever($key, [
            'rates' => $rates,
            'refresh_at' => ($fetched === null ? now()->addMinutes(15) : now()->addHours(12))->getTimestamp(),
        ]);

        return $rates;
    }

    /**
     * @return array<string, float|int>|null null when the rates could not be fetched
     */
    protected static function fetchRates(string $url, string $base): ?array
    {
        try {
            $rates = Http::timeout(10)->get($url, ['base' => $base])->throw()->json('rates');
        } catch (Throwable $e) {
            logger()->warning('Unable to fetch exchange rates: '.$e->getMessage(), ['base' => $base]);

            return null;
        }

        return is_array($rates) && $rates !== [] ? $rates : null;
    }
}
