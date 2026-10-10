<?php

namespace App\Filament\Admin\Resources\Applications;

use App\Filament\Admin\Resources\Applications\Pages\ListApplications;
use App\Filament\Admin\Resources\Applications\Pages\ViewApplication;
use App\Filament\Admin\Resources\Applications\Schemas\ApplicationInfolist;
use App\Filament\Admin\Resources\Applications\Tables\ApplicationsTable;
use App\Models\Application;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Spec 13 Community > Applications. Applications are written by contributors, so admins only review them here and
 * move them along through the Application Actions (shortlist, offer, reject; spec 6.5).
 */
class ApplicationResource extends Resource
{
    protected static ?string $model = Application::class;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return ApplicationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ApplicationsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['openRole', 'user.profile']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApplications::route('/'),
            'view' => ViewApplication::route('/{record}'),
        ];
    }
}
