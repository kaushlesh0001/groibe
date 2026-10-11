<?php

namespace App\Filament\Admin\Resources\ProofOfWork;

use App\Filament\Admin\Resources\ProofOfWork\Pages\ListProofOfWork;
use App\Filament\Admin\Resources\ProofOfWork\Pages\ViewProofOfWork;
use App\Filament\Admin\Resources\ProofOfWork\Schemas\ProofOfWorkInfolist;
use App\Filament\Admin\Resources\ProofOfWork\Tables\ProofOfWorkTable;
use App\Models\ProofOfWork;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Spec 13 Community > Proof of Work. Contributors write the wording; admins review it here and publish, send back or
 * hide it through the proof-of-work Actions (spec 9). Publishing needs the client's showcase consent.
 */
class ProofOfWorkResource extends Resource
{
    protected static ?string $model = ProofOfWork::class;

    protected static ?string $modelLabel = 'proof of work';

    protected static ?string $pluralModelLabel = 'proof of work';

    protected static ?string $slug = 'proof-of-work';

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProofOfWorkInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProofOfWorkTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user:id,name', 'project:id,code,showcase_consent', 'skills:id,name']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProofOfWork::route('/'),
            'view' => ViewProofOfWork::route('/{record}'),
        ];
    }
}
