<?php

namespace App\Services;

use App\Dto\CurrencyConversion;
use App\Services\Helpers\CurrencyHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Converts store currencies into the app currency, so prices from stores that use
 * different currencies can be compared. Rates come from a Frankfurter-compatible
 * endpoint (ECB reference rates by default), are stored in the exchange_rates table
 * by buddy:update-exchange-rates, and are only read here otherwise.
 *
 * Bound as a scoped singleton, so the stored rates are read once per request or job.
 */
class ExchangeRateService
{
    /**
     * Rates are fetched against one fixed base, the one the ECB publishes in, and any
     * pair is derived from it, so a change of app currency needs no new lookup.
     */
    protected const string BASE = 'EUR';

    /**
     * @var ?array<string, float> units of each currency per 1 EUR
     */
    protected ?array $quotes = null;

    /**
     * Conversion from $currency into the app currency. The identity when the currencies
     * match or there is no rate for either of them, which leaves the amount as scraped.
     */
    public function toAppCurrency(string $currency): CurrencyConversion
    {
        $from = strtoupper($currency);
        $to = strtoupper(CurrencyHelper::getCurrency());
        $fromQuote = $this->quotes()[$from] ?? 0;
        $toQuote = $this->quotes()[$to] ?? 0;

        return $from !== $to && $fromQuote > 0 && $toQuote > 0
            ? new CurrencyConversion($from, $to, $toQuote / $fromQuote)
            : CurrencyConversion::identity($from);
    }

    /**
     * Fetch the latest rates and store them. Returns whether they changed. A failed
     * fetch keeps the stored rates.
     */
    public function refresh(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        try {
            $rates = Http::timeout(10)
                ->get(config('services.exchange_rates.url'), ['base' => self::BASE])
                ->throw()
                ->json('rates');
        } catch (Throwable $e) {
            logger()->warning('Unable to fetch exchange rates: '.$e->getMessage());

            return false;
        }

        if (! is_array($rates) || $rates === []) {
            return false;
        }

        $quotes = array_map('floatval', [self::BASE => 1] + $rates);

        if ($quotes == $this->quotes()) {
            return false;
        }

        $now = now();
        $rows = collect($quotes)
            ->map(fn (float $rate, string $currency) => ['currency' => $currency, 'rate' => $rate, 'created_at' => $now, 'updated_at' => $now])
            ->values()
            ->all();

        DB::transaction(function () use ($rows): void {
            DB::table('exchange_rates')->delete();
            DB::table('exchange_rates')->insert($rows);
        });

        $this->quotes = $quotes;

        return true;
    }

    /**
     * Conversion is turned off by setting EXCHANGE_RATES_URL to an empty value.
     */
    public function isEnabled(): bool
    {
        return filled(config('services.exchange_rates.url'));
    }

    /**
     * @return array<string, float>
     */
    protected function quotes(): array
    {
        return $this->quotes ??= $this->isEnabled()
            ? DB::table('exchange_rates')->pluck('rate', 'currency')->map(fn ($rate): float => (float) $rate)->all()
            : [];
    }
}
