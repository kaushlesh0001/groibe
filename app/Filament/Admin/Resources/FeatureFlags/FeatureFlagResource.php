<?php

namespace App\Filament\Admin\Resources\FeatureFlags;

use App\Filament\Admin\Resources\FeatureFlags\Pages\CreateFeatureFlag;
use App\Filament\Admin\Resources\FeatureFlags\Pages\EditFeatureFlag;
use App\Filament\Admin\Resources\FeatureFlags\Pages\ListFeatureFlags;
use App\Filament\Admin\Resources\FeatureFlags\Schemas\FeatureFlagForm;
use App\Filament\Admin\Resources\FeatureFlags\Tables\FeatureFlagsTable;
use App\Models\FeatureFlag;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FeatureFlagResource extends Resource
{
    protected static ?string $model = FeatureFlag::class;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return FeatureFlagForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FeatureFlagsTable::configure($table);
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
            'index' => ListFeatureFlags::route('/'),
            'create' => CreateFeatureFlag::route('/create'),
            'edit' => EditFeatureFlag::route('/{record}/edit'),
        ];
    }
}
