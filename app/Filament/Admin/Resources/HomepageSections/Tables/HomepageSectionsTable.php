<?php

namespace App\Filament\Admin\Resources\HomepageSections\Tables;

use App\Filament\Admin\Support\NeedsReview;
use App\Models\CmsSection;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class HomepageSectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->paginated(false)
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('key')->badge()->color('gray'),
                NeedsReview::column(),
                ToggleColumn::make('is_active')
                    ->label('Shown')
                    ->disabled(fn (CmsSection $record): bool => $record->needs_review || ! (auth()->user()?->can('update', $record) ?? false)),
            ])
            ->filters([NeedsReview::filter()])
            ->recordActions([
                NeedsReview::approveAction(),
                EditAction::make(),
            ]);
    }
}
