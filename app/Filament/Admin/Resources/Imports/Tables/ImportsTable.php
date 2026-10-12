<?php

namespace App\Filament\Admin\Resources\Imports\Tables;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ImportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Uploaded')->dateTime()->sortable(),
                TextColumn::make('original_name')->label('File')->searchable()->limit(40),
                TextColumn::make('type')->badge(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('total_rows')->label('Rows')->numeric(),
                TextColumn::make('success_rows')->label('Created')->numeric(),
                TextColumn::make('skipped_rows')->label('Skipped')->numeric(),
                TextColumn::make('failed_rows')->label('Refused')->numeric(),
                TextColumn::make('creator.name')->label('By')->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')->options(ImportType::class),
                SelectFilter::make('status')->options(ImportStatus::class),
            ])
            ->recordActions([
                ViewAction::make()->label('Open'),
            ]);
    }
}
