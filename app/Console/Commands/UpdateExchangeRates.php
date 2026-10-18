<?php

namespace App\Console\Commands;

use App\Jobs\RegeneratePriceCache;
use App\Models\Store;
use App\Models\Url;
use App\Services\ExchangeRateService;
use Illuminate\Console\Command;

class UpdateExchangeRates extends Command
{
    const COMMAND = 'buddy:update-exchange-rates';

    /**
     * The name and signature of the console command.
     */
    protected $signature = self::COMMAND;

    /**
     * The console command description.
     */
    protected $description = 'Fetch exchange rates and recalculate products sold in another currency';

    /**
     * Execute the console command.
     */
    public function handle(ExchangeRateService $exchangeRates)
    {
        $foreignStoreIds = Store::all()
            ->reject(fn (Store $store): bool => $store->usesAppCurrency())
            ->modelKeys();

        // Nothing is converted, so there is no need to contact the rates endpoint.
        if ($foreignStoreIds === []) {
            return self::SUCCESS;
        }

        // Cached product prices are converted at the stored rates, so recalculate them
        // whenever the rates change.
        if ($exchangeRates->refresh()) {
            RegeneratePriceCache::dispatch(
                Url::query()->whereIn('store_id', $foreignStoreIds)->distinct()->pluck('product_id')->all()
            );
        }

        return self::SUCCESS;
    }
}
