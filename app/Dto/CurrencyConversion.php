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

    public function isConverted(): bool
    {
        return $this->from !== $this->to;
    }
}
