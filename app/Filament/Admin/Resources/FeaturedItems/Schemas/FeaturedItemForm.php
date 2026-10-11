<?php

namespace App\Filament\Admin\Resources\FeaturedItems\Schemas;

use App\Enums\FeaturedItemType;
use App\Models\FeaturedItem;
use App\Services\Website\FeaturedItemResolver;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class FeaturedItemForm
{
    public static function configure(Schema $schema): Schema
    {
        $resolver = fn (): FeaturedItemResolver => app(FeaturedItemResolver::class);
        $type = function (Get $get): ?FeaturedItemType {
            $value = $get('type');

            return $value instanceof FeaturedItemType ? $value : FeaturedItemType::tryFrom((string) $value);
        };

        return $schema->components([
            Select::make('type')
                ->options(FeaturedItemType::class)
                ->required()
                ->live()
                ->afterStateUpdated(fn ($set) => $set('item_id', null)),
            Select::make('item_id')
                ->label('Item')
                ->searchable()
                ->required()
                ->disabled(fn (Get $get): bool => $type($get) === null)
                ->getSearchResultsUsing(fn (string $search, Get $get): array => ($t = $type($get)) ? $resolver()->options($t, $search) : [])
                ->options(fn (Get $get): array => ($t = $type($get)) ? $resolver()->options($t) : [])
                ->getOptionLabelUsing(fn ($value, Get $get): ?string => ($t = $type($get)) ? $resolver()->label($t, (int) $value) : null)
                ->rule(fn (Get $get, ?FeaturedItem $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $type, $resolver, $record): void {
                    $t = $type($get);

                    if ($t === null) {
                        return;
                    }

                    if ($resolver()->label($t, (int) $value) === null) {
                        $fail('That item is not public right now, so it cannot be featured.');
                    }

                    $taken = FeaturedItem::query()->where('type', $t->value)->where('item_id', (int) $value)
                        ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->exists();

                    if ($taken) {
                        $fail('That item is already featured.');
                    }
                })
                ->helperText('Only items the public can see today are listed: public published roles, public contributor profiles, public verified organizations, published and consented proof of work, and active testimonials.'),
            TextInput::make('sort')->numeric()->required()->default(0)->helperText('Lower numbers show first.'),
            Toggle::make('is_active')->label('Featured')->default(true),
        ]);
    }
}
