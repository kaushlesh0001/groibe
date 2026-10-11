<?php

namespace App\Filament\Admin\Resources\Threads\Tables;

use App\Enums\ThreadType;
use App\Filament\Admin\Resources\Threads\ThreadResource;
use App\Models\Thread;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ThreadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')->badge()->color(fn (ThreadType $state): string => $state->color()),
                TextColumn::make('subject')->searchable()->limit(60),
                TextColumn::make('project.code')->label('Project')->placeholder('-')->searchable(),
                TextColumn::make('creator.name')->label('Started by')->toggleable(),
                TextColumn::make('unread_count')->label('Unread')->badge()->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('last_message_at')->label('Last message')->since()->placeholder('No messages')->sortable(),
            ])
            ->defaultSort('last_message_at', 'desc')
            ->filters([
                SelectFilter::make('type')->options([ThreadType::Project->value => 'Project', ThreadType::Support->value => 'Support']),
                Filter::make('unread')->label('Unread only')->query(fn (Builder $query) => $query->scopes(['havingUnread' => [auth()->user()]])),
            ])
            ->recordActions([ViewAction::make()->url(fn (Thread $record): string => ThreadResource::getUrl('view', ['record' => $record]))]);
    }
}
