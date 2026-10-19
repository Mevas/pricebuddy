<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Filament\Resources\ProductResource\Forms\UrlForm;
use App\Models\Product;
use App\Models\Url;
use App\Services\Helpers\CurrencyHelper;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UrlsTableWidget extends BaseWidget
{
    public Model|Product|null $record = null;

    public function table(Table $table): Table
    {
        /** @var Product $product */
        $product = $this->record;

        return $table
            ->heading('Product Urls')
            ->query(
                $product->urls()->with('store')->getQuery()
            )
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('store.name')
                            ->label('Store'),
                        Tables\Columns\TextColumn::make('url')
                            ->label('Url')
                            ->color('gray')
                            ->formatStateUsing(fn (string $state): HtmlString => new HtmlString('<a href="'.$state.'" title="'.$state.'" target="_blank">'.Str::limit($state, 80).'</a>')
                            ),
                    ]),
                    Tables\Columns\TextColumn::make('additional_cost')
                        ->label('Additional cost')
                        ->formatStateUsing(fn (float $state, Url $record): string => __('+ :cost', ['cost' => CurrencyHelper::toString($state, locale: $record->store?->locale, iso: $record->store?->currency)]))
                        ->tooltip(__('This link\'s own additional cost, instead of the store\'s'))
                        ->grow(false)
                        ->badge()
                        ->color('gray'),
                    Tables\Columns\TextColumn::make('price_factor')
                        ->label('Price Factor')
                        ->grow(false)
                        ->badge()
                        ->color('gray'),
                ]),

            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->form(fn (Url $record): array => UrlForm::schema($record))
                    ->using(function (Url $record, array $data): Url {
                        if (! UrlForm::save($record, $data)) {
                            throw ValidationException::withMessages([
                                'url' => __('Unable to resolve a store or price for this URL'),
                            ]);
                        }

                        return $record;
                    })
                    ->after(function () {
                        Notification::make('url_updated')
                            ->title('URL updated')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }
}
