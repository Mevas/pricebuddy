<?php

namespace App\Jobs;

use App\Console\Commands\RegeneratePriceCache as RegeneratePriceCacheCommand;
use App\Services\PriceFetcherService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

class RegeneratePriceCache implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public $timeout = PriceFetcherService::JOB_TIMEOUT;

    /**
     * Retried, because nothing queues this bulk rebuild again after the rates or a currency
     * change: until a product is next scraped, its cache would keep the old rates.
     */
    public int $tries = 3;

    public int $backoff = 60;

    /**
     * @param  ?array<int>  $productIds  null regenerates every product, an empty list none
     */
    public function __construct(public ?array $productIds = null) {}

    public function handle(): void
    {
        if ($this->productIds === []) {
            return;
        }

        Artisan::call(RegeneratePriceCacheCommand::COMMAND, $this->productIds === null ? [] : ['--product' => $this->productIds]);
    }
}
