<?php

namespace Tests\Feature\Console;

use App\Console\Commands\UpdateExchangeRates;
use App\Jobs\UpdateExchangeRates as UpdateExchangeRatesJob;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class UpdateExchangeRatesTest extends TestCase
{
    public function test_command_runs_the_job_straight_away(): void
    {
        Bus::fake();

        $this->artisan(UpdateExchangeRates::COMMAND)->assertExitCode(0);

        Bus::assertDispatchedSync(UpdateExchangeRatesJob::class, fn (UpdateExchangeRatesJob $job) => $job->productIds === []);
    }
}
