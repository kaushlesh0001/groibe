<?php

namespace App\Filament\Admin\Resources\ProofOfWork\Actions;

use App\Actions\Reputation\HideProofOfWork;
use App\Actions\Reputation\PublishProofOfWork;
use App\Actions\Reputation\ReturnProofToDraft;
use App\Enums\ProofStatus;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\ProofOfWork;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;

/** Approval actions shared by the proof-of-work list rows and the page (spec 9, 13). */
class ProofOfWorkActions
{
    public static function publish(): Action
    {
        return Action::make('publish')
            ->label('Publish')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Publishing needs the client to have given showcase consent. It shows publicly only if the contributor set it to public.')
            ->visible(fn (ProofOfWork $record): bool => self::mayDo($record, [ProofStatus::PendingApproval, ProofStatus::Hidden]))
            ->action(fn (ProofOfWork $record) => DomainActionRunner::run(
                fn () => app(PublishProofOfWork::class)($record, auth()->user()),
                'Proof of work published',
            ));
    }

    public static function sendBack(): Action
    {
        return Action::make('sendBack')
            ->label('Send back')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('The contributor is told it needs edits. Your note is included in their message.')
            ->schema([Textarea::make('note')->label('Note to the contributor')->rows(3)->maxLength(1000)])
            ->visible(fn (ProofOfWork $record): bool => self::mayDo($record, [ProofStatus::PendingApproval]))
            ->action(fn (ProofOfWork $record, array $data) => DomainActionRunner::run(
                fn () => app(ReturnProofToDraft::class)($record, auth()->user(), $data['note'] ?? null),
                'Sent back to the contributor',
            ));
    }

    public static function hide(): Action
    {
        return Action::make('hide')
            ->label('Hide')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('It disappears from every public page at once. The contributor is told.')
            ->schema([Textarea::make('note')->label('Note (optional)')->rows(2)->maxLength(1000)])
            ->visible(fn (ProofOfWork $record): bool => self::mayDo($record, [ProofStatus::Published]))
            ->action(fn (ProofOfWork $record, array $data) => DomainActionRunner::run(
                fn () => app(HideProofOfWork::class)($record, auth()->user(), $data['note'] ?? null),
                'Proof of work hidden',
            ));
    }

    /** @param  list<ProofStatus>  $from */
    private static function mayDo(ProofOfWork $record, array $from): bool
    {
        return in_array($record->status, $from, true) && (auth()->user()?->can('approve', $record) ?? false);
    }
}
