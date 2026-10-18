<?php

namespace App\Dto;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * How amounts in one currency are expressed in another. Without a rate the conversion
 * is the identity in the source currency, so an unconverted amount keeps its currency.
 */
final class CurrencyConversion
{
    /**
     * Decimal places an amount and a rate keep before they are multiplied, in PHP and in
     * SQL alike, so both round a converted amount to the same cent.
     */
    private const int AMOUNT_SCALE = 6;

    private const int RATE_SCALE = 12;

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
     * has not changed compares equal wherever it is stored. The product is computed in
     * exact decimal arithmetic, as toSql() does, because a float product can land just
     * below a half cent and round the other way. An amount that is not converted is
     * returned as scraped.
     */
    public function convert(float $amount): float
    {
        if (! $this->isConverted()) {
            return $amount;
        }

        return BigDecimal::of(sprintf('%.'.self::AMOUNT_SCALE.'F', $amount))
            ->multipliedBy($this->rateAsDecimal())
            ->toScale(2, RoundingMode::HALF_UP)
            ->toFloat();
    }

    /**
     * The unit price of a retail price that covers $factor units. The retail price is
     * converted first, so only amounts a store charges are rounded to cents. The division
     * is in floating point, as toSqlPerUnit() does.
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
     * SQL for $priceSql converted per store, the same way as convert(): a store with a
     * conversion is multiplied by its rate in decimal arithmetic and rounded to cents, and
     * every other store keeps the price as scraped.
     *
     * @param  array<int, self>  $byStore  conversions keyed by store ID
     * @param  string  $priceSql  pasted once per converted store and once more for the
     *                            others, so it must not contain ? placeholders
     * @return array{string, array<int, int|string>} the SQL and its bindings
     */
    private static function toSql(array $byStore, string $priceSql, string $storeColumn): array
    {
        $converted = array_filter($byStore, fn (self $conversion): bool => $conversion->isConverted());

        if ($converted === []) {
            return [$priceSql, []];
        }

        $amount = 'CAST('.$priceSql.' AS DECIMAL(20, '.self::AMOUNT_SCALE.'))';
        $rate = 'CAST(? AS DECIMAL(20, '.self::RATE_SCALE.'))';
        $when = " WHEN ? THEN ROUND({$amount} * {$rate}, 2)";
        $bindings = [];

        foreach ($converted as $storeId => $conversion) {
            array_push($bindings, $storeId, $conversion->rateAsDecimal());
        }

        return ["CASE {$storeColumn}".str_repeat($when, count($converted))." ELSE {$priceSql} END", $bindings];
    }

    /**
     * SQL for the unit price of $priceSql, the same way as convertPerUnit(): converted as
     * toSql() does, then divided by $factorColumn. The factor column is a double, so the
     * division is in floating point, which PHP and every database round alike.
     *
     * @param  array<int, self>  $byStore  conversions keyed by store ID
     * @param  string  $priceSql  pasted once per converted store and once more for the
     *                            others, so it must not contain ? placeholders
     * @return array{string, array<int, int|string>} the SQL and its bindings
     */
    public static function toSqlPerUnit(array $byStore, string $priceSql, string $storeColumn, string $factorColumn): array
    {
        [$price, $bindings] = self::toSql($byStore, $priceSql, $storeColumn);

        return ["({$price}) / COALESCE(NULLIF({$factorColumn}, 0), 1)", $bindings];
    }

    private function rateAsDecimal(): string
    {
        return sprintf('%.'.self::RATE_SCALE.'F', $this->rate);
    }
}
