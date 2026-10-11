<?php

namespace App\Filament\Admin\Resources\Projects\RelationManagers;

use App\Filament\Admin\Resources\Threads\ThreadResource;
use App\Models\Thread;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The project's conversation (spec 13). Read and answered on the Messages screen. */
class ThreadsRelationManager extends RelationManager
{
    protected static string $relationship = 'threads';

    protected static ?string $title = 'Messages';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ThreadResource::canViewAny();
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('subject')
            ->columns([
                TextColumn::make('subject')->limit(60),
                TextColumn::make('unread_count')->label('Unread')->badge(),
                TextColumn::make('last_message_at')->label('Last message')->since()->placeholder('No messages'),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->scopes(['visibleTo' => [auth()->user()], 'withUnread' => [auth()->user()]]))
            ->recordActions([
                ViewAction::make()->url(fn (Thread $record): string => ThreadResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
