<?php

namespace App\Console\Commands;

use App\Jobs\UpdateExchangeRates as UpdateExchangeRatesJob;
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
    public function handle()
    {
        UpdateExchangeRatesJob::dispatchSync();

        return self::SUCCESS;
    }
}
