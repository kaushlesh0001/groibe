<?php

namespace App\Filament\Admin\Resources\OpenRoles;

use App\Filament\Admin\Resources\OpenRoles\Pages\CreateOpenRole;
use App\Filament\Admin\Resources\OpenRoles\Pages\EditOpenRole;
use App\Filament\Admin\Resources\OpenRoles\Pages\ListOpenRoles;
use App\Filament\Admin\Resources\OpenRoles\RelationManagers\ApplicationsRelationManager;
use App\Filament\Admin\Resources\OpenRoles\RelationManagers\InvitesRelationManager;
use App\Filament\Admin\Resources\OpenRoles\Schemas\OpenRoleForm;
use App\Filament\Admin\Resources\OpenRoles\Tables\OpenRolesTable;
use App\Models\OpenRole;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/** Spec 13 Community > Open Roles. Writes go through the OpenRole Actions, so state rules and the audit log always apply. */
class OpenRoleResource extends Resource
{
    protected static ?string $model = OpenRole::class;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    public static function form(Schema $schema): Schema
    {
        return OpenRoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OpenRolesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            InvitesRelationManager::class,
            ApplicationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOpenRoles::route('/'),
            'create' => CreateOpenRole::route('/create'),
            'edit' => EditOpenRole::route('/{record}/edit'),
        ];
    }
}
