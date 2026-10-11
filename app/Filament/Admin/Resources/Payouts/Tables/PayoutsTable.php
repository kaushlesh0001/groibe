<?php

namespace App\Filament\Admin\Resources\Payouts\Tables;

use App\Actions\Payouts\ApprovePayout;
use App\Actions\Payouts\CancelPayout;
use App\Actions\Payouts\DeletePayout;
use App\Actions\Payouts\MarkPayoutPaid;
use App\Actions\Payouts\UpdatePayout;
use App\Enums\PayoutStatus;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\Milestone;
use App\Models\Payout;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PayoutsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('project.code')->label('Project')->searchable()->sortable(),
                TextColumn::make('user.name')->label('Contributor')->searchable(),
                TextColumn::make('milestone.title')->label('Milestone')->placeholder('-')->limit(30)->toggleable(),
                TextColumn::make('amount')->money('INR')->sortable(),
                TextColumn::make('tds_amount')->label('TDS')->money('INR')->placeholder('-')->toggleable(),
                TextColumn::make('net')->label('Net')->state(fn (Payout $record): string => $record->net())->money('INR')->toggleable(),
                TextColumn::make('status')->badge()->color(fn (PayoutStatus $state): string => $state->color())->sortable(),
                TextColumn::make('reference')->label('UTR')->placeholder('-')->searchable()->toggleable(),
                TextColumn::make('paid_at')->label('Paid on')->date()->placeholder('-')->sortable(),
                TextColumn::make('creator.name')->label('Planned by')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('notes')->limit(40)->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(PayoutStatus::class),
                SelectFilter::make('project_id')->label('Project')->relationship('project', 'code')->searchable()->preload(),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (Payout $record): bool => $record->status === PayoutStatus::Planned && (bool) auth()->user()?->can('update', $record))
                    ->fillForm(fn (Payout $record): array => ['amount' => (string) $record->amount, 'milestone_id' => $record->milestone_id, 'notes' => $record->notes])
                    ->schema(fn (Payout $record): array => [
                        TextInput::make('amount')->numeric()->required()->prefix('INR'),
                        Select::make('milestone_id')->label('Milestone')->options(self::milestones($record))->placeholder('None'),
                        Textarea::make('notes')->rows(3)->helperText('Internal. Never shown to the contributor.'),
                    ])
                    ->action(fn (Payout $record, array $data) => DomainActionRunner::run(
                        fn () => app(UpdatePayout::class)($record, auth()->user(), $data),
                        'Payout updated',
                    )),
                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription('The contributor will see this payout and be told it is approved. The figures are locked from now on.')
                    ->visible(fn (Payout $record): bool => $record->status === PayoutStatus::Planned && (bool) auth()->user()?->can('approve', $record))
                    ->action(fn (Payout $record) => DomainActionRunner::run(
                        fn () => app(ApprovePayout::class)($record, auth()->user()),
                        'Payout approved',
                    )),
                Action::make('markPaid')
                    ->label('Mark paid')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (Payout $record): bool => $record->status === PayoutStatus::Approved && (bool) auth()->user()?->can('markPaid', $record))
                    ->schema([
                        TextInput::make('reference')
                            ->label('Transaction reference (UTR)')
                            ->required()
                            ->maxLength(30)
                            ->helperText('UTR or transaction reference only. Never enter a bank account or card number.'),
                        DatePicker::make('paid_on')->label('Paid on')->required()->maxDate(now())->default(now()),
                        TextInput::make('tds')->label('TDS deducted (optional)')->numeric()->minValue(0)->prefix('INR'),
                    ])
                    ->action(fn (Payout $record, array $data) => DomainActionRunner::run(
                        fn () => app(MarkPayoutPaid::class)($record, auth()->user(), (string) $data['reference'], $data['paid_on'], $data['tds'] ?? null),
                        'Payout marked paid',
                    )),
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Payout $record): bool => in_array($record->status, [PayoutStatus::Planned, PayoutStatus::Approved], true) && (bool) auth()->user()?->can('cancel', $record))
                    ->schema([Textarea::make('reason')->required()->rows(3)])
                    ->action(fn (Payout $record, array $data) => DomainActionRunner::run(
                        fn () => app(CancelPayout::class)($record, auth()->user(), (string) $data['reason']),
                        'Payout cancelled',
                    )),
                Action::make('delete')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Only a payout that is still planned can be deleted.')
                    ->visible(fn (Payout $record): bool => $record->status === PayoutStatus::Planned && (bool) auth()->user()?->can('delete', $record))
                    ->action(fn (Payout $record) => DomainActionRunner::run(
                        fn () => app(DeletePayout::class)($record, auth()->user()),
                        'Payout deleted',
                    )),
            ]);
    }

    /** @return array<int, string> */
    private static function milestones(Payout $record): array
    {
        return Milestone::query()->where('project_id', $record->project_id)->orderBy('sort')->pluck('title', 'id')->all();
    }
}
