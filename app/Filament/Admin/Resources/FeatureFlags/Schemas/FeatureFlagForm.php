<?php

namespace App\Filament\Admin\Resources\FeatureFlags\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FeatureFlagForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('key')
                    ->required()->unique(ignoreRecord: true),
                Toggle::make('enabled')
                    ->required(),
                TextInput::make('notes'),
            ]);
    }
}
