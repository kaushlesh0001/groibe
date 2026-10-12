<?php

namespace App\Filament\Admin\Resources\DataRequests\Tables;

use App\Actions\Privacy\CompleteDeletionRequest;
use App\Actions\Privacy\CompleteExportRequest;
use App\Actions\Privacy\PrepareDataExport;
use App\Actions\Privacy\RejectDataRequest;
use App\Actions\Privacy\StartDataRequest;
use App\Enums\DataRequestStatus;
use App\Enums\DataRequestType;
use App\Exceptions\ActionBlocked;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\DataRequest;
use App\Services\Privacy\UserAnonymiser;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DataRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Requested')->dateTime()->sortable(),
                TextColumn::make('user.name')->label('Person')->searchable(),
                TextColumn::make('user.email')->label('Email')->searchable()->toggleable(),
                TextColumn::make('user.role')->label('Role')->badge()->toggleable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('handler.name')->label('Handled by')->placeholder('-')->toggleable(),
                TextColumn::make('handled_at')->label('Handled')->dateTime()->placeholder('-')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(DataRequestStatus::class)->default(DataRequestStatus::Open->value),
                SelectFilter::make('type')->options(DataRequestType::class),
            ])
            ->recordActions([
                self::start(),
                self::download(),
                self::completeExport(),
                self::completeDeletion(),
                self::reject(),
            ]);
    }

    private static function can(DataRequest $record): bool
    {
        return (bool) auth()->user()?->can('update', $record);
    }

    private static function start(): Action
    {
        return Action::make('start')
            ->label('Take it')
            ->icon('heroicon-o-hand-raised')
            ->visible(fn (DataRequest $record): bool => $record->status === DataRequestStatus::Open && self::can($record))
            ->action(fn (DataRequest $record) => DomainActionRunner::run(
                fn () => app(StartDataRequest::class)($record, auth()->user()),
                'Request taken',
            ));
    }

    private static function download(): Action
    {
        return Action::make('download')
            ->label('Download the data')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (DataRequest $record): bool => $record->type === DataRequestType::Export && $record->status === DataRequestStatus::InProgress && self::can($record))
            ->action(function (DataRequest $record): ?StreamedResponse {
                try {
                    $data = app(PrepareDataExport::class)($record, auth()->user());
                } catch (ActionBlocked $e) {
                    Notification::make()->title('That did not go through')->body($e->getMessage())->danger()->send();

                    return null;
                }

                return response()->streamDownload(
                    function () use ($data): void {
                        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    },
                    "data-export-{$record->uuid}.json",
                    ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'private, no-store'],
                );
            });
    }

    private static function completeExport(): Action
    {
        return Action::make('completeExport')
            ->label('Mark as sent')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (DataRequest $record): bool => $record->type === DataRequestType::Export && $record->status === DataRequestStatus::InProgress && self::can($record))
            ->modalDescription('Do this after you have sent the person their copy. They are told, and they can read your note.')
            ->schema([Textarea::make('note')->label('How the copy was delivered (the person can read this)')->required()->rows(3)->maxLength(1000)])
            ->action(fn (DataRequest $record, array $data) => DomainActionRunner::run(
                fn () => app(CompleteExportRequest::class)($record, auth()->user(), $data['note']),
                'Request completed',
            ));
    }

    private static function completeDeletion(): Action
    {
        return Action::make('completeDeletion')
            ->label('Erase their data')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->visible(fn (DataRequest $record): bool => $record->type === DataRequestType::Deletion && $record->status === DataRequestStatus::InProgress && self::can($record))
            ->requiresConfirmation()
            ->modalHeading('Erase this person\'s data?')
            ->modalDescription(function (DataRequest $record): string {
                $blockers = app(UserAnonymiser::class)->blockers($record->person());

                return $blockers === []
                    ? 'This anonymises their name, email, profile, skills and written answers, and closes the account. Payouts, delivered work and the audit log stay. It cannot be undone.'
                    : 'This cannot be done yet. '.implode(' ', $blockers);
            })
            ->schema([Textarea::make('note')->label('Note for the person (optional; they cannot read it after the account closes)')->rows(2)->maxLength(1000)])
            ->action(fn (DataRequest $record, array $data) => DomainActionRunner::run(
                fn () => app(CompleteDeletionRequest::class)($record, auth()->user(), $data['note'] ?? null),
                'Personal data erased and account closed',
            ));
    }

    private static function reject(): Action
    {
        return Action::make('reject')
            ->label('Reject')
            ->icon('heroicon-o-x-circle')
            ->color('gray')
            ->visible(fn (DataRequest $record): bool => $record->status->isPending() && self::can($record))
            ->modalDescription('The person is told, and they can read the reason you give.')
            ->schema([Textarea::make('reason')->label('Reason (the person can read this)')->required()->rows(3)->maxLength(1000)])
            ->action(fn (DataRequest $record, array $data) => DomainActionRunner::run(
                fn () => app(RejectDataRequest::class)($record, auth()->user(), $data['reason']),
                'Request rejected',
            ));
    }
}
