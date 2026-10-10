<?php

namespace App\Filament\Admin\Resources\Categories\Schemas;

use App\Enums\CategoryType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('slug')
                    ->required()->alphaDash()->unique(ignoreRecord: true),
                Select::make('type')
                    ->options(CategoryType::class)
                    ->required(),
                TextInput::make('sort')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}
