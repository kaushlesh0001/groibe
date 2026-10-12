<?php

namespace App\Filament\Admin\Resources\DataRequests;

use App\Filament\Admin\Resources\DataRequests\Pages\ListDataRequests;
use App\Filament\Admin\Resources\DataRequests\Tables\DataRequestsTable;
use App\Models\DataRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Spec 13 System > Data requests (spec 17.2, SCR-028): people asking for a copy of their data or its erasure. Super admins only;
 * the policy and the `data_requests` permission group are the same ones every action uses.
 */
class DataRequestResource extends Resource
{
    protected static ?string $model = DataRequest::class;

    protected static ?string $modelLabel = 'data request';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 6;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return DataRequestsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'handler']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDataRequests::route('/'),
        ];
    }
}
