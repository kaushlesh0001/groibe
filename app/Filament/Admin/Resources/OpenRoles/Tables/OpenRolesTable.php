<?php

namespace App\Filament\Admin\Resources\OpenRoles\Tables;

use App\Enums\ApplicationStatus;
use App\Enums\OpenRoleStatus;
use App\Enums\OpenRoleVisibility;
use App\Models\OpenRole;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OpenRolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('project:id,code')
                ->withCount([
                    'applications',
                    'applications as accepted_count' => fn (Builder $q) => $q->where('status', ApplicationStatus::Accepted->value),
                ]))
            ->columns([
                TextColumn::make('title')->searchable()->limit(40),
                TextColumn::make('status')->badge()->color(fn (OpenRoleStatus $state): string => $state->color())->sortable(),
                TextColumn::make('visibility')->badge()->sortable(),
                TextColumn::make('work_mode')->badge()->toggleable(),
                TextColumn::make('positions')
                    ->label('Filled')
                    ->state(fn (OpenRole $record): string => $record->getAttribute('accepted_count').' of '.$record->positions_needed),
                TextColumn::make('applications_count')->label('Applications')->sortable(),
                IconColumn::make('is_featured')->label('Featured')->boolean()->toggleable(),
                TextColumn::make('project.code')->label('Project')->placeholder('Community')->toggleable(),
                TextColumn::make('deadline')->date()->sortable()->toggleable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(OpenRoleStatus::class),
                SelectFilter::make('visibility')->options(OpenRoleVisibility::class),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
