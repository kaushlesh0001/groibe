<?php

namespace App\Filament\Admin\Resources\ProofOfWork\Tables;

use App\Enums\ProofStatus;
use App\Enums\ProofVisibility;
use App\Filament\Admin\Resources\ProofOfWork\Actions\ProofOfWorkActions;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProofOfWorkTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->limit(40),
                TextColumn::make('user.name')->label('Contributor')->searchable(),
                TextColumn::make('project.code')->label('Project')->placeholder('-')->toggleable(),
                TextColumn::make('project.showcase_consent')->label('Client consent')->badge()->placeholder('-'),
                TextColumn::make('visibility')->badge()->toggleable(),
                TextColumn::make('status')->badge()->color(fn (ProofStatus $state): string => $state->color())->sortable(),
                TextColumn::make('updated_at')->label('Updated')->dateTime()->sortable()->toggleable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(ProofStatus::class)->default(ProofStatus::PendingApproval->value),
                SelectFilter::make('visibility')->options(ProofVisibility::class),
            ])
            ->recordActions([
                ViewAction::make(),
                ProofOfWorkActions::publish(),
                ProofOfWorkActions::sendBack(),
                ProofOfWorkActions::hide(),
            ]);
    }
}
