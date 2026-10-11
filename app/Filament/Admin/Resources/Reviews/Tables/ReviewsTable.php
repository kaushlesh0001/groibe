<?php

namespace App\Filament\Admin\Resources\Reviews\Tables;

use App\Enums\ReviewDirection;
use App\Enums\ReviewRevieweeType;
use App\Enums\ReviewStatus;
use App\Filament\Admin\Resources\Reviews\Actions\ReviewActions;
use App\Models\Review;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('project.code')->label('Project')->searchable(),
                TextColumn::make('direction')->badge()->toggleable(),
                TextColumn::make('reviewer.name')->label('Reviewer')->searchable(),
                TextColumn::make('reviewee')
                    ->label('About')
                    ->state(fn (Review $record): ?string => $record->reviewee_type === ReviewRevieweeType::Organization
                        ? $record->revieweeOrganization?->name
                        : $record->revieweeUser?->name),
                TextColumn::make('rating')
                    ->label('Rating')
                    ->state(fn (Review $record): string => $record->rating() === null ? '-' : number_format($record->rating(), 1)),
                TextColumn::make('status')->badge()->color(fn (ReviewStatus $state): string => $state->color())->sortable(),
                TextColumn::make('created_at')->label('Submitted')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(ReviewStatus::class)->default(ReviewStatus::PendingModeration->value),
                SelectFilter::make('direction')->options(ReviewDirection::class),
            ])
            ->recordActions([
                ViewAction::make(),
                ReviewActions::publish(),
                ReviewActions::hide(),
            ]);
    }
}
