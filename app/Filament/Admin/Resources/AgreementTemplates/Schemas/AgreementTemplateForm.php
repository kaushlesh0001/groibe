<?php

namespace App\Filament\Admin\Resources\AgreementTemplates\Schemas;

use App\Enums\AgreementType;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AgreementTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('type')
                    ->formatStateUsing(fn ($state) => AgreementType::tryFrom((string) $state)?->getLabel())
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('version')->disabled()->dehydrated(false),
                Toggle::make('is_active')->label('Current version')->disabled()->dehydrated(false),
                TextInput::make('title')->required()->maxLength(255),
                RichEditor::make('body_html')
                    ->label('Text')
                    ->required()
                    ->helperText('Saving publishes this as a new version. Earlier versions stay unchanged so past acceptances keep their exact wording. SOW templates may use {{client_name}}, {{project_title}}, {{scope}}, {{milestones_table}}, {{total_value}}, {{payment_terms}}, {{start_date}} and {{due_date}}.')
                    ->columnSpanFull(),
            ]);
    }
}
