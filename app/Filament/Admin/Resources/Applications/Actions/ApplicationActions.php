<?php

namespace App\Filament\Admin\Resources\Applications\Actions;

use App\Actions\Applications\OfferApplication;
use App\Actions\Applications\RejectApplication;
use App\Actions\Applications\ShortlistApplication;
use App\Enums\ApplicationStatus;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\Application;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;

/** Review actions shared by the application list rows and the application page (spec 6.5, 13). */
class ApplicationActions
{
    public static function shortlist(): Action
    {
        return Action::make('shortlist')
            ->label('Shortlist')
            ->color('info')
            ->requiresConfirmation()
            ->schema([Textarea::make('note')->label('Note (internal, optional)')->rows(2)->maxLength(1000)])
            ->visible(fn (Application $record): bool => self::mayDo('update', $record, [ApplicationStatus::Submitted]))
            ->action(fn (Application $record, array $data) => DomainActionRunner::run(
                fn () => app(ShortlistApplication::class)($record, auth()->user(), $data['note'] ?? null),
                'Applicant shortlisted',
            ));
    }

    public static function offer(): Action
    {
        return Action::make('offer')
            ->label('Make offer')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('The contributor is told and can accept or decline. Accepting seats them on the linked project.')
            ->schema([Textarea::make('note')->label('Note (internal, optional)')->rows(2)->maxLength(1000)])
            ->visible(fn (Application $record): bool => self::mayDo('approve', $record, [ApplicationStatus::Shortlisted]))
            ->action(fn (Application $record, array $data) => DomainActionRunner::run(
                fn () => app(OfferApplication::class)($record, auth()->user(), $data['note'] ?? null),
                'Offer made',
            ));
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('The contributor is told the application was not taken forward. The note stays internal.')
            ->schema([Textarea::make('note')->label('Note (internal, optional)')->rows(2)->maxLength(1000)])
            ->visible(fn (Application $record): bool => self::mayDo('update', $record, [ApplicationStatus::Submitted, ApplicationStatus::Shortlisted, ApplicationStatus::Offered]))
            ->action(fn (Application $record, array $data) => DomainActionRunner::run(
                fn () => app(RejectApplication::class)($record, auth()->user(), $data['note'] ?? null),
                'Application rejected',
            ));
    }

    /** @param  list<ApplicationStatus>  $from */
    private static function mayDo(string $ability, Application $record, array $from): bool
    {
        return in_array($record->status, $from, true) && (auth()->user()?->can($ability, $record) ?? false);
    }
}
