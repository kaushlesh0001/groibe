<?php

namespace App\Filament\Admin\Resources\InvoiceRefs\Tables;

use App\Actions\Invoices\DeleteInvoiceRef;
use App\Actions\Invoices\MarkInvoiceOverdue;
use App\Actions\Invoices\MarkInvoicePaid;
use App\Actions\Invoices\SaveInvoiceRef;
use App\Actions\Invoices\SendInvoiceRef;
use App\Enums\InvoiceStatus;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\InvoiceRef;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoiceRefsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('project.code')->label('Project')->searchable()->sortable(),
                TextColumn::make('zoho_invoice_ref')->label('Zoho invoice')->searchable(),
                TextColumn::make('amount')->money('INR')->sortable(),
                TextColumn::make('status')->badge()->color(fn (InvoiceStatus $state): string => $state->color())->sortable(),
                TextColumn::make('issued_at')->label('Issued')->date()->placeholder('-')->sortable(),
                TextColumn::make('due_at')->label('Due')->date()->placeholder('-')->sortable(),
            ])
            ->defaultSort('due_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(InvoiceStatus::class),
                SelectFilter::make('project_id')->label('Project')->relationship('project', 'code')->searchable()->preload(),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil-square')
                    ->visible(fn (InvoiceRef $record): bool => $record->status !== InvoiceStatus::Paid && (bool) auth()->user()?->can('update', $record))
                    ->fillForm(fn (InvoiceRef $record): array => [
                        'zoho_invoice_ref' => $record->zoho_invoice_ref,
                        'amount' => (string) $record->amount,
                        'issued_at' => $record->issued_at?->toDateString(),
                        'due_at' => $record->due_at?->toDateString(),
                    ])
                    ->schema(self::fields())
                    ->action(fn (InvoiceRef $record, array $data) => DomainActionRunner::run(
                        fn () => app(SaveInvoiceRef::class)(auth()->user(), $record->project, $data, $record),
                        'Invoice reference updated',
                    )),
                Action::make('send')
                    ->label('Mark sent')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('info')
                    ->requiresConfirmation()
                    ->visible(fn (InvoiceRef $record): bool => $record->status === InvoiceStatus::Draft && (bool) auth()->user()?->can('update', $record))
                    ->action(fn (InvoiceRef $record) => DomainActionRunner::run(fn () => app(SendInvoiceRef::class)($record, auth()->user()), 'Invoice marked sent')),
                Action::make('overdue')
                    ->label('Mark overdue')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (InvoiceRef $record): bool => $record->status === InvoiceStatus::Sent && (bool) auth()->user()?->can('update', $record))
                    ->action(fn (InvoiceRef $record) => DomainActionRunner::run(fn () => app(MarkInvoiceOverdue::class)($record, auth()->user()), 'Invoice marked overdue')),
                Action::make('paid')
                    ->label('Mark paid')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (InvoiceRef $record): bool => in_array($record->status, [InvoiceStatus::Sent, InvoiceStatus::Overdue], true) && (bool) auth()->user()?->can('update', $record))
                    ->action(fn (InvoiceRef $record) => DomainActionRunner::run(fn () => app(MarkInvoicePaid::class)($record, auth()->user()), 'Invoice marked paid')),
                Action::make('delete')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Only a draft reference can be deleted.')
                    ->visible(fn (InvoiceRef $record): bool => $record->status === InvoiceStatus::Draft && (bool) auth()->user()?->can('delete', $record))
                    ->action(fn (InvoiceRef $record) => DomainActionRunner::run(fn () => app(DeleteInvoiceRef::class)($record, auth()->user()), 'Invoice reference deleted')),
            ]);
    }

    /** @return array<int, Component> */
    public static function fields(): array
    {
        return [
            TextInput::make('zoho_invoice_ref')->label('Zoho invoice number')->required()->maxLength(100),
            TextInput::make('amount')->numeric()->required()->prefix('INR'),
            DatePicker::make('issued_at')->label('Issue date'),
            DatePicker::make('due_at')->label('Due date')->afterOrEqual('issued_at'),
        ];
    }
}
