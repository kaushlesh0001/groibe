<?php

namespace App\Filament\Admin\Resources\Imports;

use App\Enums\ImportType;
use App\Filament\Admin\Resources\Imports\Pages\ListImports;
use App\Filament\Admin\Resources\Imports\Pages\ViewImport;
use App\Filament\Admin\Resources\Imports\Tables\ImportsTable;
use App\Models\Import;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Spec 13 People > Imports (spec 15.1). The list is limited to the imports this admin may open: their own, or all of them for a
 * super admin, and only of the kinds they hold the permission for. A record outside that answers 404 on its page.
 */
class ImportResource extends Resource
{
    protected static ?string $model = Import::class;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return ImportsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        $allowed = collect(ImportType::cases())
            ->filter(fn (ImportType $type): bool => (bool) $user?->can($type->permission()))
            ->map(fn (ImportType $type): string => $type->value)
            ->all();

        return parent::getEloquentQuery()
            ->with('creator:id,name')
            ->whereIn('type', $allowed)
            ->when(! $user?->can('audit_log.view_all'), fn (Builder $query) => $query->where('created_by', $user?->getKey()));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListImports::route('/'),
            'view' => ViewImport::route('/{record}'),
        ];
    }
}
