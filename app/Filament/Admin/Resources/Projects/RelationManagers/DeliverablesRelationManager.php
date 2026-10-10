<?php

namespace App\Filament\Admin\Resources\Projects\RelationManagers;

use App\Actions\Deliverables\CreateDeliverable;
use App\Actions\Deliverables\UpdateDeliverable;
use App\Enums\MilestoneStatus;
use App\Enums\SubmissionStatus;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\Deliverable;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Submission;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Client-facing deliverables of a project (spec 3, 13): approved submissions bundled per milestone. */
class DeliverablesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliverables';

    protected static ?string $title = 'Deliverables';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('milestone.title')->label('Milestone')->limit(30),
                TextColumn::make('title')->limit(40),
                TextColumn::make('submissions_count')->label('Approved items')->counts('submissions'),
                TextColumn::make('shared_at')->dateTime()->placeholder('Not shared'),
            ])
            ->defaultSort('deliverables.id')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('milestone:id,title,status'))
            ->headerActions([
                Action::make('addDeliverable')
                    ->label('Add deliverable')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => (bool) auth()->user()?->can('create', Deliverable::class))
                    ->schema([
                        Select::make('milestone_id')
                            ->label('Milestone')
                            ->required()
                            ->live()
                            ->options(fn (): array => $this->ownerProject()->milestones()
                                ->whereIn('status', [MilestoneStatus::InProgress->value, MilestoneStatus::InQa->value])
                                ->orderBy('sort')
                                ->pluck('title', 'id')
                                ->all()),
                        TextInput::make('title')->required()->maxLength(255),
                        Textarea::make('summary')->rows(3),
                        Select::make('submission_ids')
                            ->label('Approved work to include')
                            ->multiple()
                            ->required()
                            ->options(fn (Get $get): array => $this->approvedOptions((int) $get('milestone_id'))),
                    ])
                    ->action(function (array $data): void {
                        $milestone = Milestone::query()->where('project_id', $this->ownerProject()->getKey())->findOrFail($data['milestone_id']);

                        DomainActionRunner::run(
                            fn () => app(CreateDeliverable::class)($milestone, auth()->user(), [
                                'title' => $data['title'],
                                'summary' => $data['summary'] ?? null,
                                'submission_ids' => $data['submission_ids'],
                            ]),
                            'Deliverable added',
                        );
                    }),
            ])
            ->recordActions([
                Action::make('editDeliverable')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (Deliverable $record): bool => in_array($record->milestone->status, [MilestoneStatus::InProgress, MilestoneStatus::InQa], true)
                        && auth()->user()?->can('update', $record))
                    ->fillForm(fn (Deliverable $record): array => [
                        'title' => $record->title,
                        'summary' => $record->summary,
                        'submission_ids' => $record->submissions()->pluck('submissions.id')->all(),
                    ])
                    ->schema(fn (Deliverable $record): array => [
                        TextInput::make('title')->required()->maxLength(255),
                        Textarea::make('summary')->rows(3),
                        Select::make('submission_ids')
                            ->label('Approved work to include')
                            ->multiple()
                            ->required()
                            ->options(fn (): array => $this->approvedOptions($record->milestone_id)),
                    ])
                    ->action(fn (Deliverable $record, array $data) => DomainActionRunner::run(
                        fn () => app(UpdateDeliverable::class)($record, auth()->user(), $data),
                        'Deliverable saved',
                    )),
            ]);
    }

    /** Approved submissions of a milestone, labelled by task and version. @return array<int, string> */
    private function approvedOptions(int $milestoneId): array
    {
        if ($milestoneId === 0) {
            return [];
        }

        return Submission::query()
            ->where('status', SubmissionStatus::QaApproved->value)
            ->whereHas('task', fn (Builder $tasks) => $tasks->where('milestone_id', $milestoneId)->where('project_id', $this->ownerProject()->getKey()))
            ->with('task:id,title')
            ->get()
            ->mapWithKeys(fn (Submission $s): array => [$s->id => "{$s->task->title} (v{$s->version})"])
            ->all();
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
        return (bool) auth()->user()?->can('viewAny', Deliverable::class);
    }
}
