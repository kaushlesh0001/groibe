<?php

namespace App\Filament\Admin\Resources\Briefs;

use App\Filament\Admin\Resources\Briefs\Pages\EditBrief;
use App\Filament\Admin\Resources\Briefs\Pages\ListBriefs;
use App\Filament\Admin\Resources\Briefs\Pages\ViewBrief;
use App\Filament\Admin\Resources\Briefs\Schemas\BriefForm;
use App\Filament\Admin\Resources\Briefs\Tables\BriefsTable;
use App\Models\Brief;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/** Spec 13 Delivery > Briefs. Admins never create briefs (clients do); they review, scope and convert. */
class BriefResource extends Resource
{
    protected static ?string $model = Brief::class;

    protected static string|UnitEnum|null $navigationGroup = 'Delivery';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    public static function form(Schema $schema): Schema
    {
        return BriefForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BriefsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBriefs::route('/'),
            'view' => ViewBrief::route('/{record}'),
            'edit' => EditBrief::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
