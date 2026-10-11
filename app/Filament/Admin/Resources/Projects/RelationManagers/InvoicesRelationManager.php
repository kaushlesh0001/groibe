<?php

namespace App\Filament\Admin\Resources\Projects\RelationManagers;

use App\Enums\InvoiceStatus;
use App\Models\InvoiceRef;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The project's invoice references, read-only (spec 13). Finance acts on them from Finance > Invoice References. */
class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoiceRefs';

    protected static ?string $title = 'Invoices';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) auth()->user()?->can('viewAny', InvoiceRef::class);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('zoho_invoice_ref')
            ->columns([
                TextColumn::make('zoho_invoice_ref')->label('Zoho invoice'),
                TextColumn::make('amount')->money('INR'),
                TextColumn::make('status')->badge()->color(fn (InvoiceStatus $state): string => $state->color()),
                TextColumn::make('issued_at')->label('Issued')->date()->placeholder('-'),
                TextColumn::make('due_at')->label('Due')->date()->placeholder('-'),
            ])
            ->defaultSort('due_at', 'desc');
    }
}
