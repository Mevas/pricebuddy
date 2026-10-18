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

    public function convert(float $amount): float
    {
        return $amount * $this->rate;
    }

    /**
     * Turn a converted amount back into the source currency.
     */
    public function revert(float $amount): float
    {
        return $amount / $this->rate;
    }

    public function isConverted(): bool
    {
        return $this->from !== $this->to;
    }
}
