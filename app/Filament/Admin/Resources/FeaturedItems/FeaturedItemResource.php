<?php

namespace App\Filament\Admin\Resources\FeaturedItems;

use App\Filament\Admin\Resources\FeaturedItems\Pages\CreateFeaturedItem;
use App\Filament\Admin\Resources\FeaturedItems\Pages\EditFeaturedItem;
use App\Filament\Admin\Resources\FeaturedItems\Pages\ListFeaturedItems;
use App\Filament\Admin\Resources\FeaturedItems\Schemas\FeaturedItemForm;
use App\Filament\Admin\Resources\FeaturedItems\Tables\FeaturedItemsTable;
use App\Models\FeaturedItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Spec 13 Website > Featured Items. Admin picks which open roles, contributors, organizations, proof of work and
 * testimonials the homepage features, and in what order. Only records the public can see today can be picked.
 */
class FeaturedItemResource extends Resource
{
    protected static ?string $model = FeaturedItem::class;

    protected static ?string $modelLabel = 'featured item';

    protected static string|UnitEnum|null $navigationGroup = 'Website';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    public static function form(Schema $schema): Schema
    {
        return FeaturedItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FeaturedItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeaturedItems::route('/'),
            'create' => CreateFeaturedItem::route('/create'),
            'edit' => EditFeaturedItem::route('/{record}/edit'),
        ];
    }
}
