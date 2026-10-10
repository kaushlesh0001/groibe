<?php

namespace App\Filament\Admin\Resources\Projects\RelationManagers;

use App\Enums\TaskStatus;
use App\Filament\Admin\Resources\Projects\Support\WorkPlanActions;
use App\Models\Project;
use App\Models\Task;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Every task of a project with its milestone and assignee (spec 13). Writes go through the Task Actions. */
class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Tasks';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')->limit(50)->searchable(),
                TextColumn::make('milestone.title')->label('Milestone')->limit(30),
                TextColumn::make('assignment.user.name')->label('Assigned to')->placeholder('Unassigned'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (TaskStatus $state): string => $state->color()),
                TextColumn::make('estimate_hours')->label('Estimate (h)')->placeholder('-'),
                TextColumn::make('due_date')->date()->placeholder('-')->sortable(),
            ])
            ->defaultSort('id')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['milestone:id,title', 'assignment:id,user_id', 'assignment.user:id,name']))
            ->filters([
                SelectFilter::make('status')->options(TaskStatus::class),
                SelectFilter::make('milestone_id')->label('Milestone')->relationship('milestone', 'title'),
            ])
            ->headerActions([
                WorkPlanActions::addTask(fn (): Project => $this->ownerProject()),
            ])
            ->recordActions([
                WorkPlanActions::assignTask(),
                WorkPlanActions::editTask(),
                WorkPlanActions::deleteTask(),
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
        return (bool) auth()->user()?->can('viewAny', Task::class);
    }
}
