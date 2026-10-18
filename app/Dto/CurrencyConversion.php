<?php

namespace App\Dto;

/**
 * How amounts in one currency are expressed in another. When no rate is known the
 * conversion is the identity in the source currency, so an amount is never relabelled
 * with a currency it was not converted into.
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

    public function isConverted(): bool
    {
        return $this->from !== $this->to;
    }
}
