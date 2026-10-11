<?php

namespace App\Filament\Admin\Resources\InvoiceRefs;

use App\Filament\Admin\Resources\InvoiceRefs\Pages\ListInvoiceRefs;
use App\Filament\Admin\Resources\InvoiceRefs\Tables\InvoiceRefsTable;
use App\Models\InvoiceRef;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Spec 13 Finance > Invoice References (SCR-024). Pointers to invoices in Zoho Books, with a status. Finance and super admins
 * manage them; delivery managers read. Clients never see them.
 */
class InvoiceRefResource extends Resource
{
    protected static ?string $model = InvoiceRef::class;

    protected static ?string $modelLabel = 'invoice reference';

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return InvoiceRefsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('project:id,code,title');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoiceRefs::route('/'),
        ];
    }
}
