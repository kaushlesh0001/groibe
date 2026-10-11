<?php

namespace App\Filament\Admin\Resources\Pages\Tables;

use App\Filament\Admin\Support\NeedsReview;
use App\Models\Page;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class PagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('title')
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('slug')->prefix('/')->searchable(),
                NeedsReview::column(),
                ToggleColumn::make('is_active')
                    ->label('Published')
                    ->disabled(fn (Page $record): bool => $record->needs_review || ! (auth()->user()?->can('update', $record) ?? false)),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([NeedsReview::filter()])
            ->recordActions([NeedsReview::approveAction(), EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
