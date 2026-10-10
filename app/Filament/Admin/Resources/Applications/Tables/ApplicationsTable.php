<?php

namespace App\Filament\Admin\Resources\Applications\Tables;

use App\Enums\ApplicationStatus;
use App\Filament\Admin\Resources\Applications\Actions\ApplicationActions;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ApplicationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('Applicant')->searchable(),
                TextColumn::make('openRole.title')->label('Role')->searchable()->limit(40),
                TextColumn::make('status')->badge()->color(fn (ApplicationStatus $state): string => $state->color())->sortable(),
                TextColumn::make('created_at')->label('Applied')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(ApplicationStatus::class),
                SelectFilter::make('open_role_id')->label('Role')->relationship('openRole', 'title')->searchable()->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
                ApplicationActions::shortlist(),
                ApplicationActions::offer(),
                ApplicationActions::reject(),
            ]);
    }
}
