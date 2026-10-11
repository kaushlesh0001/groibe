<?php

namespace App\Filament\Admin\Resources\Reviews;

use App\Filament\Admin\Resources\Reviews\Pages\ListReviews;
use App\Filament\Admin\Resources\Reviews\Pages\ViewReview;
use App\Filament\Admin\Resources\Reviews\Schemas\ReviewInfolist;
use App\Filament\Admin\Resources\Reviews\Tables\ReviewsTable;
use App\Models\Review;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Spec 13 Community > Reviews (moderation). Reviews are written by clients and contributors, so admins read them here
 * and publish or hide them through the Review Actions (spec 9). The one thing an admin writes is an internal review,
 * from the list page.
 */
class ReviewResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return ReviewInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ReviewsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['reviewer:id,name', 'project:id,code,title', 'revieweeUser:id,name', 'revieweeOrganization:id,name']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviews::route('/'),
            'view' => ViewReview::route('/{record}'),
        ];
    }
}
