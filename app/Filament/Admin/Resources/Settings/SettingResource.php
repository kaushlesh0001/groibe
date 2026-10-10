<?php

namespace App\Filament\Admin\Resources\Settings;

use App\Filament\Admin\Resources\Settings\Pages\CreateSetting;
use App\Filament\Admin\Resources\Settings\Pages\EditSetting;
use App\Filament\Admin\Resources\Settings\Pages\ListSettings;
use App\Filament\Admin\Resources\Settings\Schemas\SettingForm;
use App\Filament\Admin\Resources\Settings\Tables\SettingsTable;
use App\Models\Setting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SettingResource extends Resource
{
    protected static ?string $model = Setting::class;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return SettingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SettingsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSettings::route('/'),
            'create' => CreateSetting::route('/create'),
            'edit' => EditSetting::route('/{record}/edit'),
        ];
    }
}
