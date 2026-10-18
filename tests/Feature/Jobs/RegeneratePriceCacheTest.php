<?php

namespace Tests\Feature\Jobs;

use App\Jobs\RegeneratePriceCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class RegeneratePriceCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_calls_artisan_command()
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('buddy:regenerate-price-cache', [])
            ->andReturn(0);

        $job = new RegeneratePriceCache;
        $job->handle();

        $this->assertTrue(true); // Job executed successfully
    }

    public function test_job_limits_the_command_to_the_given_products(): void
    {
        Artisan::shouldReceive('call')
            ->once()
            ->with('buddy:regenerate-price-cache', ['--product' => [3, 5]])
            ->andReturn(0);

        (new RegeneratePriceCache([3, 5]))->handle();
    }

    public function test_job_with_no_products_does_nothing(): void
    {
        Artisan::shouldReceive('call')->never();

        (new RegeneratePriceCache([]))->handle();
    }
}
