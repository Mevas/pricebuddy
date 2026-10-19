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
        // What buying from a store costs on top of the price, such as shipping, in the
        // store's currency, and the reduced cost from a price on, such as free shipping
        // over a minimum order. A link's own cost replaces its store's. Prices and costs
        // are both doubles so PHP and SQL add them the same way.
        Schema::table('stores', function (Blueprint $table) {
            $table->double('additional_cost')->nullable()->after('settings');
            $table->double('additional_cost_reduced_from')->nullable()->after('additional_cost');
            $table->double('additional_cost_reduced')->nullable()->after('additional_cost_reduced_from');
        });

        Schema::table('urls', function (Blueprint $table) {
            $table->double('additional_cost')->nullable()->after('price_factor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['additional_cost', 'additional_cost_reduced_from', 'additional_cost_reduced']);
        });

        Schema::table('urls', function (Blueprint $table) {
            $table->dropColumn('additional_cost');
        });
    }
};
