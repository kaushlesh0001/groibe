<?php

namespace App\Filament\Admin\Resources\Contributors\Actions;

use App\Actions\Contributors\ApproveContributor;
use App\Actions\Contributors\ReinstateContributor;
use App\Actions\Contributors\RejectContributor;
use App\Actions\Contributors\SuspendContributor;
use App\Enums\OnboardingState;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\Profile;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;

/** Review actions shared by the contributor list rows and the contributor page (spec 6.10, SCR-011, 13). */
class ContributorActions
{
    public static function approve(): Action
    {
        return Action::make('approve')
            ->label('Approve')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('The contributor becomes active. The email and Member Agreement gates are checked again.')
            ->visible(fn (Profile $record): bool => self::mayDo('approve', $record, OnboardingState::PendingReview))
            ->action(fn (Profile $record) => DomainActionRunner::run(
                fn () => app(ApproveContributor::class)($record, auth()->user()),
                'Contributor approved',
            ));
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Rejection is final in v1. The reason is kept in the audit log and is not sent to the contributor.')
            ->schema([Textarea::make('reason')->label('Reason (internal)')->required()->rows(3)->maxLength(1000)])
            ->visible(fn (Profile $record): bool => self::mayDo('reject', $record, OnboardingState::PendingReview))
            ->action(fn (Profile $record, array $data) => DomainActionRunner::run(
                fn () => app(RejectContributor::class)($record, auth()->user(), $data['reason']),
                'Contributor rejected',
            ));
    }

    public static function suspend(): Action
    {
        return Action::make('suspend')
            ->label('Suspend')
            ->color('danger')
            ->requiresConfirmation()
            ->schema([Textarea::make('reason')->label('Reason (internal)')->required()->rows(3)->maxLength(1000)])
            ->visible(fn (Profile $record): bool => self::mayDo('suspend', $record, OnboardingState::Active))
            ->action(fn (Profile $record, array $data) => DomainActionRunner::run(
                fn () => app(SuspendContributor::class)($record, auth()->user(), $data['reason']),
                'Contributor suspended',
            ));
    }

    public static function reinstate(): Action
    {
        return Action::make('reinstate')
            ->label('Reinstate')
            ->color('success')
            ->requiresConfirmation()
            ->schema([Textarea::make('reason')->label('Note (internal, optional)')->rows(2)->maxLength(1000)])
            ->visible(fn (Profile $record): bool => self::mayDo('reinstate', $record, OnboardingState::Suspended))
            ->action(fn (Profile $record, array $data) => DomainActionRunner::run(
                fn () => app(ReinstateContributor::class)($record, auth()->user(), $data['reason'] ?? null),
                'Contributor reinstated',
            ));
    }

    private static function mayDo(string $ability, Profile $record, OnboardingState $requiredState): bool
    {
        return $record->onboarding_state === $requiredState && (auth()->user()?->can($ability, $record) ?? false);
    }
}
