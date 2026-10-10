<?php

namespace App\Filament\Admin\Resources\AgreementTemplates;

use App\Filament\Admin\Resources\AgreementTemplates\Pages\CreateAgreementTemplate;
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

/** Versioned legal text (spec 8). Super admin publishes new versions, which never rewrite an old one; other admins read. */
class AgreementTemplateResource extends Resource
{
    protected static ?string $model = AgreementTemplate::class;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Agreement Templates';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    public static function form(Schema $schema): Schema
    {
        return AgreementTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AgreementTemplatesTable::configure($table);
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
            'create' => CreateAgreementTemplate::route('/create'),
            'view' => ViewAgreementTemplate::route('/{record}'),
            'edit' => EditAgreementTemplate::route('/{record}/edit'),
        ];
    }
}
