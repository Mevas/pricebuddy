<?php

namespace App\Filament\Resources\ProductResource\Forms;

use App\Models\Url;
use App\Rules\StoreUrl;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;

/**
 * The form that edits a product URL, on the product page and in its URL table.
 */
class UrlForm
{
    /**
     * @return array<int, Component>
     */
    public static function schema(Url $url): array
    {
        return [
            TextInput::make('url')
                ->label('URL')
                ->required()
                ->rules([new StoreUrl]),
            TextInput::make('price_factor')
                ->label('Price Factor')
                ->numeric()
                ->default(1)
                ->minValue(0.01)
                ->required(),
            self::additionalCostInput()->prefix($url->store?->currency),
        ];
    }

    /**
     * A link's own additional cost, also asked for when a link is added.
     */
    public static function additionalCostInput(): TextInput
    {
        return TextInput::make('additional_cost')
            ->label(__('Additional cost'))
            ->numeric()
            ->minValue(0)
            ->helperText(__('Replaces the store\'s additional cost for this link, for example a marketplace seller\'s shipping. Leave empty to use the store\'s.'));
    }

    /**
     * The link's own additional cost from the input's state: none when it is left empty.
     */
    public static function additionalCost(mixed $state): ?float
    {
        return blank($state) ? null : (float) $state;
    }

    /**
     * Apply the form to $url in one save, with a new address if it has one, so the fresh
     * price of a new address is taken with the new factor and cost. A new address that
     * fails to resolve changes nothing.
     *
     * @param  array<string, mixed>  $data
     * @return bool whether the new address resolved
     */
    public static function save(Url $url, array $data): bool
    {
        $attributes = [
            'price_factor' => (float) $data['price_factor'],
            'additional_cost' => self::additionalCost($data['additional_cost'] ?? null),
        ];

        $saved = trim($data['url']) === $url->url
            ? $url->update($attributes)
            : $url->changeUrl($data['url'], attributes: $attributes);

        if (! $saved) {
            return false;
        }

        $url->syncStoredPricesForCurrentFactor();
        $url->product->updatePriceCache();

        return true;
    }
}
