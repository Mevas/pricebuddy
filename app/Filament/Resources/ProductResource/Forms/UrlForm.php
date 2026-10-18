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
            TextInput::make('additional_cost')
                ->label('Additional cost')
                ->numeric()
                ->minValue(0)
                ->prefix($url->store?->currency)
                ->helperText(__('Replaces the store\'s additional cost for this link, for example a marketplace seller\'s shipping. Leave empty to use the store\'s.')),
        ];
    }

    /**
     * Apply the form to $url. A new address is resolved first, so one that fails changes
     * nothing. A link that moves to another store loses its own cost, which was in the old
     * store's currency.
     *
     * @param  array<string, mixed>  $data
     * @return bool whether the new address resolved
     */
    public static function save(Url $url, array $data): bool
    {
        $storeId = $url->store_id;

        if (trim($data['url']) !== $url->url && ! $url->changeUrl($data['url'])) {
            return false;
        }

        $url->price_factor = (float) $data['price_factor'];

        if ($url->store_id === $storeId) {
            $url->additional_cost = blank($data['additional_cost'] ?? null) ? null : (float) $data['additional_cost'];
        }

        $url->save();
        $url->syncStoredPricesForCurrentFactor();
        $url->product->updatePriceCache();

        return true;
    }
}
