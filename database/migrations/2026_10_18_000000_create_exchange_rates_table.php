<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Units of each currency per 1 EUR, as last fetched by buddy:update-exchange-rates.
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->string('currency', 3)->primary();
            $table->double('rate');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
