<?php

namespace App\Filament\Admin\Resources\Skills\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class SkillForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('slug')
                    ->required()->alphaDash()->unique(ignoreRecord: true),
                Select::make('category_id')
                    ->relationship('category', 'name'),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}
