<?php

namespace App\Dto;

/**
 * How amounts in one currency are expressed in another. Without a rate the conversion
 * is the identity in the source currency, so an unconverted amount keeps its currency.
 */
final class CurrencyConversion
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly float $rate = 1.0,
    ) {}

    public static function identity(string $currency): self
    {
        return new self($currency, $currency);
    }

    /**
     * A converted amount is rounded to cents, like the price aggregates, so a price that
     * has not changed compares equal wherever it is stored. An amount that is not
     * converted is returned as scraped.
     */
    public function convert(float $amount): float
    {
        return $this->isConverted() ? round($amount * $this->rate, 2) : $amount;
    }

    /**
     * The unit price of a retail price that covers $factor units. The retail price is
     * converted first, so only amounts a store charges are rounded to cents.
     */
    public function convertPerUnit(float $price, float $factor): float
    {
        return $this->convert($price) / ($factor ?: 1);
    }

    public function isConverted(): bool
    {
        return $this->from !== $this->to;
    }

    /**
     * SQL for $priceColumn converted per store, the same way as convert(): a store with a
     * conversion is multiplied by its rate and rounded to cents (in decimal arithmetic,
     * which rounds halves like PHP), and every other store keeps the price as scraped.
     *
     * @param  array<int, self>  $byStore  conversions keyed by store ID
     * @return array{string, array<int, int|string>} the SQL and its bindings
     */
    public static function toSql(array $byStore, string $priceColumn, string $storeColumn): array
    {
        $converted = array_filter($byStore, fn (self $conversion): bool => $conversion->isConverted());

        if ($converted === []) {
            return [$priceColumn, []];
        }

        $when = " WHEN ? THEN ROUND(CAST({$priceColumn} AS DECIMAL(20, 6)) * CAST(? AS DECIMAL(20, 12)), 2)";
        $bindings = [];

        foreach ($converted as $storeId => $conversion) {
            array_push($bindings, $storeId, sprintf('%.12F', $conversion->rate));
        }

        return ["CASE {$storeColumn}".str_repeat($when, count($converted))." ELSE {$priceColumn} END", $bindings];
    }
}
