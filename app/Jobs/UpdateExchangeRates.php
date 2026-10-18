<?php

namespace App\Jobs;

use App\Models\Store;
use App\Models\Url;
use App\Services\ExchangeRateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fetch the exchange rates, then recalculate the products that need it: the given ones,
 * plus every product sold in another currency when the rates changed.
 */
class UpdateExchangeRates implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * @param  ?array<int>  $productIds  products to recalculate in any case; null for every product
     */
    public function __construct(public ?array $productIds = []) {}

    public function handle(ExchangeRateService $exchangeRates): void
    {
        $foreignStoreIds = Store::all()
            ->reject(fn (Store $store): bool => $store->usesAppCurrency())
            ->modelKeys();

        // Without a store in another currency nothing is converted, so the endpoint is not
        // contacted. Cached prices are converted at the stored rates, so a change in rates
        // recalculates every product sold in another currency.
        $ratesChanged = $foreignStoreIds !== [] && $exchangeRates->refresh();

        $productIds = $ratesChanged && $this->productIds !== null
            ? array_values(array_unique([
                ...$this->productIds,
                ...Url::query()->whereIn('store_id', $foreignStoreIds)->distinct()->pluck('product_id')->all(),
            ]))
            : $this->productIds;

        if ($productIds !== []) {
            RegeneratePriceCache::dispatch($productIds);
        }
    }
}
