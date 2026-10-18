<?php

namespace App\Jobs;

use App\Console\Commands\UpdateExchangeRates as UpdateExchangeRatesCommand;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * Fetch exchange rates now rather than at the next hourly run, for example when a store
 * starts using another currency. Unique while queued, so changing many stores at once
 * makes one request.
 */
class UpdateExchangeRates implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function handle(): void
    {
        Artisan::call(UpdateExchangeRatesCommand::COMMAND);
    }
}
