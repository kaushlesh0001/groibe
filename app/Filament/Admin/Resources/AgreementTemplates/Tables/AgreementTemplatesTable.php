<?php

namespace App\Filament\Admin\Resources\AgreementTemplates\Tables;

use App\Enums\AgreementType;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AgreementTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')->badge()->sortable(),
                TextColumn::make('version')->sortable(),
                TextColumn::make('title')->searchable(),
                IconColumn::make('is_active')->label('Current')->boolean(),
                TextColumn::make('effective_from')->dateTime()->sortable(),
                TextColumn::make('acceptances_count')->counts('acceptances')->label('Acceptances'),
            ])
            ->defaultSort('type')
            ->filters([
                SelectFilter::make('type')->options(AgreementType::class),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->label('Revise'),
            ]);
    }
}
