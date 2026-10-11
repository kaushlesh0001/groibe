<?php

namespace App\Filament\Admin\Resources\Reviews\Actions;

use App\Actions\Reviews\HideReview;
use App\Actions\Reviews\PublishReview;
use App\Actions\Reviews\SubmitInternalReview;
use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Enums\ReviewStatus;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\Assignment;
use App\Models\Project;
use App\Models\Review;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;

/** Moderation and internal-review actions shared by the review list rows and the review page (spec 9, 13). */
class ReviewActions
{
    public static function publish(): Action
    {
        return Action::make('publish')
            ->label('Publish')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Published client reviews count toward the contributor\'s reputation. Organization and internal reviews stay admin-only.')
            ->schema([Textarea::make('note')->label('Note (internal, optional)')->rows(2)->maxLength(1000)])
            ->visible(fn (Review $record): bool => self::mayDo($record, [ReviewStatus::PendingModeration, ReviewStatus::Hidden]))
            ->action(fn (Review $record, array $data) => DomainActionRunner::run(
                fn () => app(PublishReview::class)($record, auth()->user(), $data['note'] ?? null),
                'Review published',
            ));
    }

    public static function hide(): Action
    {
        return Action::make('hide')
            ->label('Hide')
            ->color('danger')
            ->requiresConfirmation()
            ->schema([Textarea::make('note')->label('Note (internal, optional)')->rows(2)->maxLength(1000)])
            ->visible(fn (Review $record): bool => self::mayDo($record, [ReviewStatus::PendingModeration, ReviewStatus::Published]))
            ->action(fn (Review $record, array $data) => DomainActionRunner::run(
                fn () => app(HideReview::class)($record, auth()->user(), $data['note'] ?? null),
                'Review hidden',
            ));
    }

    /** Super admin only, by the permission matrix (Pass 6 decision). Never shown to the contributor or counted in reputation. */
    public static function addInternal(): Action
    {
        return Action::make('addInternal')
            ->label('Add internal review')
            ->icon('heroicon-o-plus')
            ->visible(fn (): bool => auth()->user()?->can('create', Review::class) ?? false)
            ->schema([
                Select::make('project_id')
                    ->label('Project')
                    ->options(fn (): array => Project::query()
                        ->whereIn('status', [ProjectStatus::Accepted->value, ProjectStatus::Closed->value])
                        ->orderByDesc('id')->limit(200)->pluck('code', 'id')->all())
                    ->searchable()
                    ->live()
                    ->required(),
                Select::make('contributor_id')
                    ->label('Contributor')
                    ->options(fn ($get): array => $get('project_id') === null ? [] : User::query()
                        ->whereIn('id', Assignment::query()
                            ->where('project_id', $get('project_id'))
                            ->whereIn('status', [AssignmentStatus::Active->value, AssignmentStatus::Completed->value, AssignmentStatus::Removed->value])
                            ->select('user_id'))
                        ->pluck('name', 'id')->all())
                    ->required(),
                ...collect(['quality', 'communication', 'reliability', 'timeliness'])->map(
                    fn (string $criterion) => Select::make($criterion)
                        ->options([1 => '1', 2 => '2', 3 => '3', 4 => '4', 5 => '5'])
                        ->required(),
                )->all(),
                Textarea::make('comment')->rows(3)->maxLength(2000),
            ])
            ->action(function (array $data): void {
                DomainActionRunner::run(
                    fn () => app(SubmitInternalReview::class)(
                        Project::query()->findOrFail($data['project_id']),
                        auth()->user(),
                        User::query()->findOrFail($data['contributor_id']),
                        collect($data)->only(['quality', 'communication', 'reliability', 'timeliness'])->all(),
                        $data['comment'] ?? null,
                    ),
                    'Internal review added',
                );
            });
    }

    /** @param  list<ReviewStatus>  $from */
    private static function mayDo(Review $record, array $from): bool
    {
        return in_array($record->status, $from, true) && (auth()->user()?->can('moderate', $record) ?? false);
    }
}
