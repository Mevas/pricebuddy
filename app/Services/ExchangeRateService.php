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
    public const string CACHE_KEY = 'exchange_rates';

    /**
     * Rates are fetched against one fixed base, the one the ECB publishes in, and any
     * pair is derived from it, so a change of app currency needs no new lookup.
     */
    protected const string BASE = 'EUR';

    /**
     * Conversion from $currency into the app currency. The identity when the currencies
     * match or there is no rate for either of them, which leaves the amount as scraped.
     */
    public static function toAppCurrency(string $currency): CurrencyConversion
    {
        $from = strtoupper($currency);
        $to = strtoupper(CurrencyHelper::getCurrency());

        if ($from === $to) {
            return CurrencyConversion::identity($from);
        }

        $quotes = self::quotes();
        $fromQuote = (float) ($quotes[$from] ?? 0);
        $toQuote = (float) ($quotes[$to] ?? 0);

        return $fromQuote > 0 && $toQuote > 0
            ? new CurrencyConversion($from, $to, $toQuote / $fromQuote)
            : CurrencyConversion::identity($from);
    }

    /**
     * Units of each currency per 1 EUR, from the last successful fetch. Refreshed every
     * 12 hours; a failed refresh keeps the previous quotes and is retried after a few
     * minutes.
     *
     * @return array<string, float|int>
     */
    protected static function quotes(): array
    {
        $url = config('services.exchange_rates.url');

        if (blank($url)) {
            return [];
        }

        // Memoised per request or queued job, so a long-running worker still sees refreshes.
        $cache = Cache::memo();

        /** @var array{quotes: array<string, float|int>, refresh_at: int}|null $stored */
        $stored = $cache->get(self::CACHE_KEY);

        if ($stored && $stored['refresh_at'] > now()->getTimestamp()) {
            return $stored['quotes'];
        }

        $fetched = self::fetchQuotes($url);
        $quotes = $fetched ?? $stored['quotes'] ?? [];

        $cache->forever(self::CACHE_KEY, [
            'quotes' => $quotes,
            'refresh_at' => ($fetched === null ? now()->addMinutes(15) : now()->addHours(12))->getTimestamp(),
        ]);

        return $quotes;
    }

    /**
     * @return array<string, float|int>|null null when the rates could not be fetched
     */
    protected static function fetchQuotes(string $url): ?array
    {
        try {
            $rates = Http::timeout(10)->get($url, ['base' => self::BASE])->throw()->json('rates');
        } catch (Throwable $e) {
            logger()->warning('Unable to fetch exchange rates: '.$e->getMessage());

            return null;
        }

        return is_array($rates) && $rates !== [] ? [self::BASE => 1] + $rates : null;
    }
}
