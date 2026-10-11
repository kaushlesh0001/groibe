<?php

namespace App\Filament\Admin\Resources\Reviews\Schemas;

use App\Enums\ReviewRevieweeType;
use App\Enums\ReviewStatus;
use App\Models\Review;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ReviewInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Review')
                ->columns(2)
                ->schema([
                    TextEntry::make('project.code')->label('Project'),
                    TextEntry::make('status')->badge()->color(fn (ReviewStatus $state): string => $state->color()),
                    TextEntry::make('direction')->badge(),
                    TextEntry::make('reviewer.name')->label('Reviewer'),
                    TextEntry::make('reviewee')
                        ->label('About')
                        ->state(fn (Review $record): ?string => $record->reviewee_type === ReviewRevieweeType::Organization
                            ? $record->revieweeOrganization?->name
                            : $record->revieweeUser?->name),
                    TextEntry::make('created_at')->label('Submitted')->dateTime(),
                    TextEntry::make('quality')->placeholder('-')->visible(fn (Review $record): bool => in_array('quality', $record->direction->criteria(), true)),
                    TextEntry::make('communication')->placeholder('-'),
                    TextEntry::make('reliability')->placeholder('-')->visible(fn (Review $record): bool => in_array('reliability', $record->direction->criteria(), true)),
                    TextEntry::make('timeliness')->placeholder('-')->visible(fn (Review $record): bool => in_array('timeliness', $record->direction->criteria(), true)),
                    TextEntry::make('clarity')->placeholder('-')->visible(fn (Review $record): bool => in_array('clarity', $record->direction->criteria(), true)),
                    TextEntry::make('professionalism')->placeholder('-')->visible(fn (Review $record): bool => in_array('professionalism', $record->direction->criteria(), true)),
                    TextEntry::make('feedback_time')->placeholder('-')->visible(fn (Review $record): bool => in_array('feedback_time', $record->direction->criteria(), true)),
                    TextEntry::make('fairness')->placeholder('-')->visible(fn (Review $record): bool => in_array('fairness', $record->direction->criteria(), true)),
                    TextEntry::make('comment')->placeholder('No comment')->columnSpanFull(),
                ]),
        ]);
    }
}
