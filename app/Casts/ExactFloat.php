<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a float with every significant digit. PDO writes a float to the 14 digits of the
 * precision setting, so a computed price would read back as another double, and SQL that
 * repeats the computation would no longer compare equal with it.
 *
 * @implements CastsAttributes<?float, float|int|string|null>
 */
class ExactFloat implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?float
    {
        return $value === null ? null : (float) $value;
    }

    /**
     * Seventeen significant digits read back as the same double, whatever the precision
     * settings are.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : sprintf('%.17g', (float) $value);
    }
}
