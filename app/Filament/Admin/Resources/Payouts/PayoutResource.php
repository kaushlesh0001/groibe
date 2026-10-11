<?php

namespace App\Filament\Admin\Resources\Payouts;

use App\Filament\Admin\Resources\Payouts\Pages\ListPayouts;
use App\Filament\Admin\Resources\Payouts\Tables\PayoutsTable;
use App\Models\Payout;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Spec 13 Finance > Payouts (SCR-024). Finance and super admins plan, approve, pay, cancel and export; delivery managers
 * read. Every write goes through the Payout Actions, so the state rules, the agreed-amount cap and the audit log always apply.
 */
class PayoutResource extends Resource
{
    protected static ?string $model = Payout::class;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return PayoutsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['project:id,code,title', 'user:id,name', 'milestone:id,title', 'creator:id,name']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayouts::route('/'),
        ];
    }
}
