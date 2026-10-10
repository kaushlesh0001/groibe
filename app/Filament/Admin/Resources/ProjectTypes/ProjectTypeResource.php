<?php

namespace App\Filament\Admin\Resources\ProjectTypes;

use App\Filament\Admin\Resources\ProjectTypes\Pages\CreateProjectType;
use App\Filament\Admin\Resources\ProjectTypes\Pages\EditProjectType;
use App\Filament\Admin\Resources\ProjectTypes\Pages\ListProjectTypes;
use App\Filament\Admin\Resources\ProjectTypes\Schemas\ProjectTypeForm;
use App\Filament\Admin\Resources\ProjectTypes\Tables\ProjectTypesTable;
use App\Models\ProjectType;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ProjectTypeResource extends Resource
{
    protected static ?string $model = ProjectType::class;

    protected static string|UnitEnum|null $navigationGroup = 'Taxonomy';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ProjectTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProjectTypesTable::configure($table);
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
            'index' => ListProjectTypes::route('/'),
            'create' => CreateProjectType::route('/create'),
            'edit' => EditProjectType::route('/{record}/edit'),
        ];
    }
}
