<?php

namespace App\Filament\Admin\Resources\Projects\Tables;

use App\Enums\ProjectStatus;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('title')->searchable()->limit(40),
                TextColumn::make('organization.name')->label('Organization')->searchable()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (ProjectStatus $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('contract_value')->money('INR')->sortable()->toggleable(),
                TextColumn::make('deliveryManager.name')->label('Delivery manager')->toggleable(),
                TextColumn::make('due_date')->date()->sortable()->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(ProjectStatus::class),
                SelectFilter::make('organization_id')->label('Organization')->relationship('organization', 'name')->searchable()->preload(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
