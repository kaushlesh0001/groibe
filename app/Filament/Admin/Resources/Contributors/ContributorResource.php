<?php

namespace App\Filament\Admin\Resources\Contributors;

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Contributors\Pages\ListContributors;
use App\Filament\Admin\Resources\Contributors\Pages\ViewContributor;
use App\Filament\Admin\Resources\Contributors\Schemas\ContributorInfolist;
use App\Filament\Admin\Resources\Contributors\Tables\ContributorsTable;
use App\Models\Profile;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Spec 13 People > Contributors, with the review queue. Backed by the profile so the contributor
 * policy rules apply, and scoped to contributors so client profiles never show up here.
 * Contributors are created by registration or import, never by hand in this resource.
 */
class ContributorResource extends Resource
{
    protected static ?string $model = Profile::class;

    protected static ?string $modelLabel = 'contributor';

    protected static ?string $slug = 'contributors';

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('contributors.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return ContributorInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ContributorsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('user', fn (Builder $user) => $user->where('role', UserRole::Contributor->value))
            ->with('user');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContributors::route('/'),
            'view' => ViewContributor::route('/{record}'),
        ];
    }
}
