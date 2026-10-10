<?php

namespace App\Filament\Admin\Resources\AgreementTemplates;

use App\Filament\Admin\Resources\AgreementTemplates\Pages\EditAgreementTemplate;
use App\Filament\Admin\Resources\AgreementTemplates\Pages\ListAgreementTemplates;
use App\Filament\Admin\Resources\AgreementTemplates\Pages\ViewAgreementTemplate;
use App\Filament\Admin\Resources\AgreementTemplates\Schemas\AgreementTemplateForm;
use App\Filament\Admin\Resources\AgreementTemplates\Tables\AgreementTemplatesTable;
use App\Models\AgreementTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/** Versioned legal text (spec 8). Super admin edits, which publishes a new version; other admins read. */
class AgreementTemplateResource extends Resource
{
    protected static ?string $model = AgreementTemplate::class;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    public static function form(Schema $schema): Schema
    {
        return AgreementTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AgreementTemplatesTable::configure($table);
    }

    /** Templates are seeded per type and then versioned, never created from scratch in the panel. */
    public static function canCreate(): bool
    {
        return false;
    }

    /** Only the active version can be revised. Older versions are history. */
    public static function canEdit(Model $record): bool
    {
        return $record instanceof AgreementTemplate && $record->is_active && parent::canEdit($record);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgreementTemplates::route('/'),
            'view' => ViewAgreementTemplate::route('/{record}'),
            'edit' => EditAgreementTemplate::route('/{record}/edit'),
        ];
    }
}
