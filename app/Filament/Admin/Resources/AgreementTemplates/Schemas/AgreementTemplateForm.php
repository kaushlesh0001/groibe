<?php

namespace App\Filament\Admin\Resources\AgreementTemplates\Schemas;

use App\Enums\AgreementType;
use App\Models\AgreementTemplate;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class AgreementTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->label('Agreement type')
                    ->options(AgreementType::class)
                    ->required()
                    ->live()
                    ->disabled(fn (string $operation): bool => $operation !== 'create')
                    ->dehydrated(fn (string $operation): bool => $operation === 'create')
                    // Starting from the current wording saves retyping when only part of it changes.
                    ->afterStateUpdated(function (mixed $state, Set $set, string $operation): void {
                        $type = $state instanceof AgreementType ? $state : AgreementType::tryFrom((string) $state);

                        if ($operation !== 'create' || $type === null) {
                            return;
                        }

                        $current = AgreementTemplate::currentFor($type);
                        $set('title', $current->title ?? $type->getLabel());
                        $set('body_html', $current?->body_html);
                    }),
                Text::make(function (Get $get): string {
                    $type = $get('type');
                    $type = $type instanceof AgreementType ? $type : AgreementType::tryFrom((string) $type);

                    return $type === null ? 'Pick a type to see where it is used.' : 'Where this is used: '.$type->usedAt();
                })->columnSpanFull(),
                Text::make('This text still carries the placeholder note. While it does, it counts as not published and cannot be accepted. Remove the note when you paste the real wording (SCR-036).')
                    ->visible(function (Get $get): bool {
                        $state = $get('body_html');
                        $text = is_string($state) ? $state : json_encode($state);

                        return is_string($text) && str_contains($text, AgreementTemplate::PLACEHOLDER_NOTE);
                    })
                    ->columnSpanFull(),
                TextInput::make('version')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation !== 'create'),
                Toggle::make('is_active')
                    ->label('Current version')
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn (string $operation): bool => $operation !== 'create'),
                TextInput::make('title')->required()->maxLength(255),
                DateTimePicker::make('effective_from')
                    ->label('Effective date')
                    ->required()
                    ->default(now())
                    ->maxDate(now()->endOfDay())
                    ->helperText('A version applies as soon as it is published, so the date cannot be in the future. Backdate it if the wording took effect earlier.'),
                RichEditor::make('body_html')
                    ->label('Text')
                    ->required()
                    ->live(onBlur: true)
                    ->helperText('Saving publishes this as a new version. Earlier versions stay unchanged so past acceptances keep their exact wording. SOW templates may use {{client_name}}, {{project_title}}, {{scope}}, {{milestones_table}}, {{total_value}}, {{payment_terms}}, {{start_date}} and {{due_date}}.')
                    ->columnSpanFull(),
            ]);
    }
}
