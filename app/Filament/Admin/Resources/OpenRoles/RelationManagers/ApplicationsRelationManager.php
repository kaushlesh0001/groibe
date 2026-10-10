<?php

namespace App\Filament\Admin\Resources\OpenRoles\RelationManagers;

use App\Enums\ApplicationStatus;
use App\Filament\Admin\Resources\Applications\ApplicationResource;
use App\Models\Application;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Applications to this role. The review itself happens on the application page. */
class ApplicationsRelationManager extends RelationManager
{
    protected static string $relationship = 'applications';

    protected static ?string $title = 'Applications';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user:id,name'))
            ->columns([
                TextColumn::make('user.name')->label('Applicant'),
                TextColumn::make('status')->badge()->color(fn (ApplicationStatus $state): string => $state->color()),
                TextColumn::make('created_at')->label('Applied')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('review')
                    ->label('Review')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Application $record): string => ApplicationResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
