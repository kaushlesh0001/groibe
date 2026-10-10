<?php

namespace App\Filament\Admin\Resources\Organizations\Tables;

use App\Enums\OrganizationStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class OrganizationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('industry')->searchable()->toggleable(),
                TextColumn::make('city')->searchable()->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (OrganizationStatus $state): string => match ($state) {
                        OrganizationStatus::Verified => 'success',
                        OrganizationStatus::Pending => 'warning',
                        OrganizationStatus::Suspended => 'danger',
                    })
                    ->sortable(),
                IconColumn::make('can_submit_briefs')->boolean()->toggleable(),
                IconColumn::make('is_public')->boolean()->toggleable(),
                TextColumn::make('users_count')->counts('users')->label('Users'),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(OrganizationStatus::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
