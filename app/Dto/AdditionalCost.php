<?php

namespace App\Dto;

/**
 * What buying a listing costs on top of its price, such as shipping, in the store's
 * currency. It is waived from a price of $freeFrom, like free shipping over a minimum
 * order. The minimum is checked against the listing's price alone.
 */
final class AdditionalCost
{
    public function __construct(
        public readonly float $amount = 0.0,
        public readonly ?float $freeFrom = null,
    ) {}

    /**
     * The cost of buying at $price. A price that is not positive is no price to buy at,
     * so it has no cost.
     */
    public function on(float $price): float
    {
        if ($price <= 0 || ($this->freeFrom !== null && $price >= $this->freeFrom)) {
            return 0.0;
        }

        return $this->amount;
    }

    /**
     * SQL for on() over $priceColumn, from the cost columns of the urls and stores tables,
     * which the query must join. A link's own cost replaces its store's, free-from and all.
     */
    public static function toSql(string $priceColumn): string
    {
        return 'CASE'
            ." WHEN {$priceColumn} <= 0 THEN 0"
            .' WHEN urls.additional_cost IS NOT NULL THEN urls.additional_cost'
            ." WHEN {$priceColumn} >= stores.additional_cost_free_from THEN 0"
            .' ELSE COALESCE(stores.additional_cost, 0) END';
    }
}
