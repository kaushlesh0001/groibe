<?php

namespace App\Filament\Admin\Resources\Organizations\Schemas;

use App\Enums\OrganizationStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class OrganizationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, callable $set, ?string $old, callable $get): void {
                        if (blank($get('slug')) && filled($state)) {
                            $set('slug', Str::slug($state));
                        }
                    }),
                TextInput::make('slug')
                    ->required()
                    ->alphaDash()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                TextInput::make('legal_name')->maxLength(255),
                TextInput::make('gstin')->label('GSTIN')->maxLength(20),
                TextInput::make('website')->url()->maxLength(255),
                TextInput::make('industry')->maxLength(255),
                TextInput::make('size_band')->maxLength(30),
                Textarea::make('description')->columnSpanFull(),
                TextInput::make('city')->maxLength(255),
                TextInput::make('state')->maxLength(255),
                TextInput::make('country')->default('India')->maxLength(255),
                Select::make('status')
                    ->options(OrganizationStatus::class)
                    ->default(OrganizationStatus::Pending->value)
                    ->required(),
                Toggle::make('can_submit_briefs')
                    ->helperText('Lets an unverified organization submit briefs (spec 6.11).'),
                Toggle::make('is_public'),
                Toggle::make('is_featured'),
                TextInput::make('zoho_crm_id')->label('Zoho CRM ID')->maxLength(255),
            ]);
    }
}
