<?php

namespace App\Filament\Admin\Resources\HomepageSections;

use App\Filament\Admin\Resources\HomepageSections\Pages\CreateHomepageSection;
use App\Filament\Admin\Resources\HomepageSections\Pages\EditHomepageSection;
use App\Filament\Admin\Resources\HomepageSections\Pages\ListHomepageSections;
use App\Filament\Admin\Resources\HomepageSections\Schemas\HomepageSectionForm;
use App\Filament\Admin\Resources\HomepageSections\Tables\HomepageSectionsTable;
use App\Models\CmsSection;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Spec 13 Website > Homepage Sections. The eleven sections of spec 10.1 are ordered, switched on and off and edited
 * here, with no code change. Sections are hidden rather than deleted, so a section is never lost by accident.
 */
class HomepageSectionResource extends Resource
{
    protected static ?string $model = CmsSection::class;

    protected static ?string $modelLabel = 'homepage section';

    protected static string|UnitEnum|null $navigationGroup = 'Website';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return HomepageSectionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HomepageSectionsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHomepageSections::route('/'),
            'create' => CreateHomepageSection::route('/create'),
            'edit' => EditHomepageSection::route('/{record}/edit'),
        ];
    }
}
