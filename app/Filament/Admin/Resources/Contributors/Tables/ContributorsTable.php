<?php

namespace App\Filament\Admin\Resources\Contributors\Tables;

use App\Actions\Contributors\ReinstateContributor;
use App\Actions\Contributors\SuspendContributor;
use App\Actions\Onboarding\QueueInvites;
use App\Enums\OnboardingState;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Filament\Admin\Resources\Contributors\Actions\ContributorActions;
use App\Models\Profile;
use App\Models\User;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;

class ContributorsTable
{
    /** @var list<OnboardingState> spec 6.10 states, without the client-only ones */
    public const CONTRIBUTOR_STATES = [
        OnboardingState::Registered,
        OnboardingState::ProfileComplete,
        OnboardingState::PendingReview,
        OnboardingState::Active,
        OnboardingState::Rejected,
        OnboardingState::Suspended,
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('Name')->searchable()->sortable(),
                TextColumn::make('user.email')->label('Email')->searchable()->toggleable(),
                TextColumn::make('headline')->limit(40)->searchable()->toggleable(),
                TextColumn::make('onboarding_state')
                    ->label('State')
                    ->badge()
                    ->color(fn (OnboardingState $state): string => $state->color())
                    ->sortable(),
                IconColumn::make('is_public')->label('Public')->boolean()->toggleable(),
                TextColumn::make('city')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Registered')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('onboarding_state')
                    ->label('State')
                    ->options(collect(self::CONTRIBUTOR_STATES)
                        ->mapWithKeys(fn (OnboardingState $state) => [$state->value => $state->getLabel()])
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make()->label('Open'),
                ContributorActions::approve(),
                ContributorActions::reject(),
                ContributorActions::suspend(),
                ContributorActions::reinstate(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::bulkSuspend(),
                    self::bulkReinstate(),
                    self::bulkInvite(),
                ]),
            ]);
    }

    private static function bulkSuspend(): BulkAction
    {
        return BulkAction::make('suspendSelected')
            ->label('Suspend selected')
            ->color('danger')
            ->requiresConfirmation()
            ->schema([Textarea::make('reason')->label('Reason (internal)')->required()->rows(3)->maxLength(1000)])
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records, array $data) => self::runBulk($records, 'suspended', fn (Profile $profile) => app(SuspendContributor::class)($profile, auth()->user(), $data['reason'])));
    }

    private static function bulkInvite(): BulkAction
    {
        return BulkAction::make('sendInvites')
            ->label('Send invites')
            ->requiresConfirmation()
            ->modalDescription('Queues an activation link for each selected contributor who has not signed up yet. Emails are spread out over time.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records): void {
                $result = app(QueueInvites::class)(User::query()->whereIn('id', $records->pluck('user_id'))->get(), auth()->user());

                Notification::make()
                    ->title($result['queued'].' invites queued'.($result['skipped'] > 0 ? ', '.$result['skipped'].' skipped' : ''))
                    ->body($result['skipped'] > 0 ? 'Skipped contributors have already signed up, or you may not invite them.' : null)
                    ->color($result['skipped'] > 0 ? 'warning' : 'success')
                    ->send();
            });
    }

    private static function bulkReinstate(): BulkAction
    {
        return BulkAction::make('reinstateSelected')
            ->label('Reinstate selected')
            ->color('success')
            ->requiresConfirmation()
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => self::runBulk($records, 'reinstated', fn (Profile $profile) => app(ReinstateContributor::class)($profile, auth()->user())));
    }

    /**
     * Applies one transition Action per record. Records the state machine or policy refuses are
     * skipped and counted, so a mixed selection never fails halfway with no report.
     *
     * @param  Collection<int, Profile>  $records
     * @param  callable(Profile): mixed  $callback
     */
    private static function runBulk(Collection $records, string $verb, callable $callback): void
    {
        $done = 0;
        $skipped = 0;

        foreach ($records as $profile) {
            try {
                $callback($profile);
                $done++;
            } catch (IllegalTransition|ActionBlocked|AuthorizationException) {
                $skipped++;
            }
        }

        Notification::make()
            ->title("{$done} {$verb}".($skipped > 0 ? ", {$skipped} skipped" : ''))
            ->body($skipped > 0 ? 'Skipped contributors were in the wrong state or you are not allowed to change them.' : null)
            ->color($skipped > 0 ? 'warning' : 'success')
            ->send();
    }
}
