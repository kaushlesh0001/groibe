<?php

namespace App\Filament\Admin\Resources\AgreementTemplates\Tables;

use App\Enums\AgreementType;
use App\Models\AgreementTemplate;
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
                // A placeholder counts as not published and cannot be accepted (spec 8, SCR-036), so say so where it is listed.
                TextColumn::make('wording')
                    ->label('Wording')
                    ->state(fn (AgreementTemplate $record): string => $record->isPlaceholder() ? 'Still a placeholder' : 'Real wording')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Still a placeholder' ? 'warning' : 'success'),
                TextColumn::make('effective_from')->dateTime()->sortable(),
                TextColumn::make('type_usage')
                    ->label('Where this is used')
                    ->state(fn ($record): string => $record->type->usedAt())
                    ->wrap()
                    ->toggleable(),
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
