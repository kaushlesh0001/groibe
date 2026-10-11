<?php

namespace App\Filament\Admin\Resources\Faqs\Tables;

use App\Enums\FaqAudience;
use App\Filament\Admin\Support\NeedsReview;
use App\Models\Faq;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FaqsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('audience')->badge()->sortable(),
                TextColumn::make('question')->searchable()->limit(80)->wrap(),
                NeedsReview::column(),
                ToggleColumn::make('is_active')
                    ->label('Shown')
                    ->disabled(fn (Faq $record): bool => $record->needs_review || ! (auth()->user()?->can('update', $record) ?? false)),
            ])
            ->filters([SelectFilter::make('audience')->options(FaqAudience::class), NeedsReview::filter()])
            ->recordActions([NeedsReview::approveAction(), EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
