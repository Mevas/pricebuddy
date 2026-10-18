<?php

namespace App\Jobs;

use App\Console\Commands\RegeneratePriceCache as RegeneratePriceCacheCommand;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

class RegeneratePriceCache implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

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
