<?php

namespace App\Filament\Admin\Resources\Briefs\Tables;

use App\Enums\BriefStatus;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BriefsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->limit(50),
                TextColumn::make('organization.name')->label('Organization')->searchable()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (BriefStatus $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('budget_band')
                    ->formatStateUsing(fn (?string $state) => config('groibe.briefs.budget_bands.'.$state, $state))
                    ->toggleable(),
                TextColumn::make('assignedAdmin.name')->label('Assigned to')->toggleable(),
                TextColumn::make('submitted_at')->dateTime()->sortable(),
            ])
            // Drafts are the client's private work in progress, so the admin queue starts at submitted.
            ->modifyQueryUsing(fn ($query) => $query->where('status', '!=', BriefStatus::Draft->value))
            ->defaultSort('submitted_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(collect(BriefStatus::cases())
                    ->reject(fn (BriefStatus $status) => $status === BriefStatus::Draft)
                    ->mapWithKeys(fn (BriefStatus $status) => [$status->value => $status->getLabel()])
                    ->all()),
            ])
            ->recordActions([
                EditAction::make()->label('Open'),
                ViewAction::make()->label('View'),
            ]);
    }
}
