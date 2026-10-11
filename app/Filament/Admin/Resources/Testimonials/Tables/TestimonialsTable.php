<?php

namespace App\Filament\Admin\Resources\Testimonials\Tables;

use App\Filament\Admin\Support\NeedsReview;
use App\Models\Testimonial;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class TestimonialsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->reorderable('sort')
            ->columns([
                TextColumn::make('author_name')->searchable(),
                TextColumn::make('organization_name')->toggleable(),
                TextColumn::make('quote')->limit(60),
                NeedsReview::column(),
                ToggleColumn::make('is_active')
                    ->label('Shown')
                    ->disabled(fn (Testimonial $record): bool => $record->needs_review || ! (auth()->user()?->can('update', $record) ?? false)),
            ])
            ->filters([NeedsReview::filter()])
            ->recordActions([NeedsReview::approveAction(), EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
