<?php

namespace App\Filament\Admin\Resources\Projects\RelationManagers;

use App\Enums\MilestoneStatus;
use App\Filament\Admin\Resources\Projects\Support\WorkPlanActions;
use App\Models\Milestone;
use App\Models\Project;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The milestones of a project in plan order (spec 6.9, 13). Writes go through the Milestone Actions. */
class MilestonesRelationManager extends RelationManager
{
    protected static string $relationship = 'milestones';

    protected static ?string $title = 'Milestones';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('sort')->label('#'),
                TextColumn::make('title')->limit(50),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (MilestoneStatus $state): string => $state->color()),
                TextColumn::make('tasks_count')->label('Tasks')->counts('tasks'),
                TextColumn::make('due_date')->date()->placeholder('-'),
                TextColumn::make('value')->money('INR')->placeholder('-')->toggleable(),
                TextColumn::make('shared_at')->dateTime()->placeholder('Not shared')->toggleable(),
                TextColumn::make('client_note')->label('Client note')->limit(60)->placeholder('-')->tooltip(fn (Milestone $record): ?string => $record->client_note),
            ])
            ->defaultSort('sort')
            ->headerActions([
                WorkPlanActions::addMilestone(fn (): Project => $this->ownerProject()),
            ])
            ->recordActions([
                WorkPlanActions::shareMilestone(),
                WorkPlanActions::reopenMilestone(),
                WorkPlanActions::editMilestone(),
                WorkPlanActions::deleteMilestone(),
            ]);
    }

    private function ownerProject(): Project
    {
        /** @var Project */
        return $this->getOwnerRecord();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('viewAny', Milestone::class);
    }
}
