<?php

namespace App\Dto;

/**
 * How a link's scraped prices become the prices products compare: what buying costs,
 * the price plus its additional cost, in the app currency.
 */
final class ListingPricing
{
    public function __construct(
        public readonly CurrencyConversion $conversion,
        public readonly AdditionalCost $additionalCost = new AdditionalCost,
    ) {}

    /**
     * What buying at $price costs in the app currency, per unit of the $factor units the
     * price covers. The cost is charged once per purchase in the store's currency, so it is
     * added before the price is converted and split into units.
     */
    public function total(float $price, float $factor = 1): float
    {
        return $this->conversion->convertPerUnit($price + $this->additionalCost->on($price), $factor);
    }

    /**
     * SQL for total() over price rows, which the query must join to their urls and stores.
     * Prices and costs are doubles in both PHP and SQL, so they add up the same.
     *
     * @param  array<int, CurrencyConversion>  $conversionsByStore  conversions keyed by store ID
     * @return array{string, array<int, int|string>} the SQL and its bindings
     */
    public static function toSql(array $conversionsByStore, string $priceColumn, string $storeColumn, string $factorColumn): array
    {
        return CurrencyConversion::toSqlPerUnit(
            $conversionsByStore,
            "({$priceColumn} + ".AdditionalCost::toSql($priceColumn).')',
            $storeColumn,
            $factorColumn,
        );
    }
}
