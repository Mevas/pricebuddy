<?php

namespace App\Dto;

use App\Models\Url;
use App\Services\Helpers\CurrencyHelper;
use Illuminate\Database\Eloquent\Builder;

/**
 * What buying from a link costs: its price plus the additional cost of buying it, such
 * as shipping, converted from the store's currency into the app currency. Prices compare
 * by this wherever they are ranked or alerted on. toSql() repeats forUrl(), costAt() and
 * total() in SQL, so they must change together.
 */
final class ListingPricing
{
    /**
     * @param  ?float  $cost  the additional cost, in the store's currency
     * @param  ?float  $reducedFrom  the price from which $reducedCost applies instead
     * @param  ?float  $reducedCost  the cost from that price; none means free
     */
    public function __construct(
        public readonly CurrencyConversion $conversion,
        private readonly ?float $cost = null,
        private readonly ?float $reducedFrom = null,
        private readonly ?float $reducedCost = null,
    ) {}

    /**
     * A link's own cost, when it has one, replaces its store's, reduction and all.
     */
    public static function forUrl(Url $url): self
    {
        $store = $url->store;
        $conversion = $store?->currencyConversion() ?? CurrencyConversion::identity(CurrencyHelper::getCurrency());

        if ($url->additional_cost !== null) {
            return new self($conversion, $url->additional_cost);
        }

        return new self($conversion, $store?->additional_cost, $store?->additional_cost_reduced_from, $store?->additional_cost_reduced);
    }

    /**
     * The additional cost of buying at $price, in the store's currency. A price that is not
     * positive is no price to buy at, so it has no cost.
     */
    public function costAt(float $price): float
    {
        if ($price <= 0) {
            return 0.0;
        }

        if ($this->reducedFrom !== null && $price >= $this->reducedFrom) {
            return $this->reducedCost ?? 0.0;
        }

        return $this->cost ?? 0.0;
    }

    /**
     * What buying at $price costs in the app currency, per unit of the $factor units the
     * price covers. The cost is charged once per purchase in the store's currency, so it is
     * added before the price is converted and split into units.
     */
    public function total(float $price, float $factor = 1): float
    {
        return $this->conversion->convertPerUnit($price + $this->costAt($price), $factor);
    }

    /**
     * SQL for total() per unit of each price in $prices, a query over prices joined to their
     * urls, which this joins to their stores. Prices and costs are doubles in PHP and in SQL
     * alike, so they add up the same.
     *
     * @param  array<int, CurrencyConversion>  $conversionsByStore  conversions keyed by store ID
     * @return array{string, array<int, int|string>} the SQL and its bindings
     */
    public static function toSql(Builder $prices, array $conversionsByStore): array
    {
        $prices->leftJoin('stores', 'stores.id', '=', 'urls.store_id');

        $costAt = 'CASE'
            .' WHEN prices.price <= 0 THEN 0'
            .' WHEN urls.additional_cost IS NOT NULL THEN urls.additional_cost'
            .' WHEN prices.price >= stores.additional_cost_reduced_from THEN COALESCE(stores.additional_cost_reduced, 0)'
            .' ELSE COALESCE(stores.additional_cost, 0) END';

        return CurrencyConversion::toSqlPerUnit($conversionsByStore, "(prices.price + {$costAt})", 'urls.store_id', 'prices.price_factor');
    }
}
