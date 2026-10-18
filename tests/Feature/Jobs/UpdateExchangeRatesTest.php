<?php

namespace Tests\Feature\Jobs;

use App\Jobs\UpdateExchangeRates;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class UpdateExchangeRatesTest extends TestCase
{
    public function test_job_calls_the_artisan_command(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('buddy:update-exchange-rates')
            ->andReturn(0);

        (new UpdateExchangeRates)->handle();
    }
}
