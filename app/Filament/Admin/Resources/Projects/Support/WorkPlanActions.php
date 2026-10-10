<?php

namespace App\Filament\Admin\Resources\Projects\Support;

use App\Actions\Milestones\CreateMilestone;
use App\Actions\Milestones\DeleteMilestone;
use App\Actions\Milestones\UpdateMilestone;
use App\Actions\Tasks\AssignTask;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\DeleteTask;
use App\Actions\Tasks\UpdateTask;
use App\Enums\MilestoneStatus;
use App\Enums\TaskStatus;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\Assignment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/** The milestone and task actions shared by the project relation managers and the workspace page. Every write goes through an Action class. */
class WorkPlanActions
{
    /** @param  Closure(): Project  $project */
    public static function addMilestone(Closure $project): Action
    {
        return Action::make('addMilestone')
            ->label('Add milestone')
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => (bool) auth()->user()?->can('create', Milestone::class))
            ->schema(self::milestoneFields())
            ->action(fn (array $data) => DomainActionRunner::run(
                fn () => app(CreateMilestone::class)($project(), auth()->user(), $data),
                'Milestone added',
            ));
    }

    public static function editMilestone(): Action
    {
        return Action::make('editMilestone')
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (Milestone $record): bool => (bool) auth()->user()?->can('update', $record))
            ->fillForm(fn (Milestone $record): array => $record->only(['title', 'description', 'due_date', 'value']))
            ->schema(self::milestoneFields())
            ->action(fn (Milestone $record, array $data) => DomainActionRunner::run(
                fn () => app(UpdateMilestone::class)($record, auth()->user(), $data),
                'Milestone saved',
            ));
    }

    public static function deleteMilestone(): Action
    {
        return Action::make('deleteMilestone')
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Milestone $record): bool => $record->status === MilestoneStatus::Planned && auth()->user()?->can('delete', $record))
            ->action(fn (Milestone $record) => DomainActionRunner::run(
                fn () => app(DeleteMilestone::class)($record, auth()->user()),
                'Milestone deleted',
            ));
    }

    /** @param  Closure(): Project  $project */
    public static function addTask(Closure $project): Action
    {
        return Action::make('addTask')
            ->label('Add task')
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => (bool) auth()->user()?->can('create', Task::class))
            ->schema(fn (): array => [
                Select::make('milestone_id')
                    ->label('Milestone')
                    ->required()
                    ->options(fn (): array => Milestone::query()
                        ->where('project_id', $project()->getKey())
                        ->whereIn('status', [MilestoneStatus::Planned->value, MilestoneStatus::InProgress->value])
                        ->ordered()
                        ->pluck('title', 'id')
                        ->all()),
                ...self::taskFields(),
                self::assignmentSelect($project)->label('Assign to'),
            ])
            ->action(function (array $data) use ($project): void {
                $milestone = Milestone::query()->where('project_id', $project()->getKey())->findOrFail($data['milestone_id']);

                DomainActionRunner::run(
                    fn () => app(CreateTask::class)($milestone, auth()->user(), array_filter(
                        $data,
                        fn ($value, $key) => $key !== 'milestone_id' && filled($value),
                        ARRAY_FILTER_USE_BOTH,
                    )),
                    'Task added',
                );
            });
    }

    public static function editTask(): Action
    {
        return Action::make('editTask')
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (Task $record): bool => $record->status !== TaskStatus::Approved && auth()->user()?->can('update', $record))
            ->fillForm(fn (Task $record): array => $record->only(['title', 'description', 'estimate_hours', 'due_date']))
            ->schema(self::taskFields())
            ->action(fn (Task $record, array $data) => DomainActionRunner::run(
                fn () => app(UpdateTask::class)($record, auth()->user(), $data),
                'Task saved',
            ));
    }

    public static function assignTask(): Action
    {
        return Action::make('assignTask')
            ->label('Assign')
            ->icon('heroicon-o-user')
            ->visible(fn (Task $record): bool => $record->status === TaskStatus::Todo && auth()->user()?->can('assign', $record))
            ->fillForm(fn (Task $record): array => ['assignment_id' => $record->assignment_id])
            ->schema(fn (Task $record): array => [
                self::assignmentSelect(fn (): Project => Project::query()->findOrFail($record->project_id))
                    ->label('Contributor')
                    ->helperText('Leave empty to unassign.'),
            ])
            ->action(fn (Task $record, array $data) => DomainActionRunner::run(
                fn () => app(AssignTask::class)($record, auth()->user(), $data['assignment_id'] ?? null),
                'Assignment saved',
            ));
    }

    public static function deleteTask(): Action
    {
        return Action::make('deleteTask')
            ->label('Delete')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Task $record): bool => $record->status === TaskStatus::Todo && auth()->user()?->can('delete', $record))
            ->action(fn (Task $record) => DomainActionRunner::run(
                fn () => app(DeleteTask::class)($record, auth()->user()),
                'Task deleted',
            ));
    }

    /** Active seats of the project, labelled by contributor name and role. @return array<int, string> */
    public static function assignmentOptions(Project $project): array
    {
        return Assignment::query()
            ->where('project_id', $project->getKey())
            ->active()
            ->with('user:id,name')
            ->get()
            ->mapWithKeys(fn (Assignment $a): array => [$a->id => "{$a->user->name} ({$a->role_title})"])
            ->all();
    }

    /** @param  Closure(): Project  $project */
    private static function assignmentSelect(Closure $project): Select
    {
        return Select::make('assignment_id')->options(fn (): array => self::assignmentOptions($project()));
    }

    /** @return list<mixed> */
    private static function milestoneFields(): array
    {
        return [
            TextInput::make('title')->required()->maxLength(255),
            Textarea::make('description')->rows(3),
            DatePicker::make('due_date'),
            TextInput::make('value')->numeric()->minValue(0)->prefix('INR'),
        ];
    }

    /** @return list<mixed> */
    private static function taskFields(): array
    {
        return [
            TextInput::make('title')->required()->maxLength(255),
            Textarea::make('description')->rows(3),
            TextInput::make('estimate_hours')->label('Estimate (hours)')->numeric()->minValue(0)->maxValue(9999),
            DatePicker::make('due_date'),
        ];
    }
}
