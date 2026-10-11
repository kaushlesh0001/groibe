<?php

namespace App\Filament\Admin\Resources\FeaturedItems\Tables;

use App\Enums\FeaturedItemType;
use App\Models\FeaturedItem;
use App\Services\Website\FeaturedItemResolver;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FeaturedItemsTable
{
    public static function configure(Table $table): Table
    {
        // Labels for every pick, one query per type, built the first time a row asks for one.
        $labels = null;
        $label = function (FeaturedItem $record) use (&$labels): string {
            $labels ??= app(FeaturedItemResolver::class)->labels(FeaturedItem::query()->limit(500)->get());

            return $labels[$record->type->value.':'.$record->item_id] ?? 'Missing or no longer public';
        };

        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('item')
                    ->label('Item')
                    ->state($label)
                    ->color(fn (string $state): ?string => $state === 'Missing or no longer public' ? 'danger' : null)
                    ->wrap(),
                ToggleColumn::make('is_active')
                    ->label('Featured')
                    ->disabled(fn (FeaturedItem $record): bool => ! (auth()->user()?->can('update', $record) ?? false)),
            ])
            ->filters([SelectFilter::make('type')->options(FeaturedItemType::class)])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
