<?php

namespace App\Filament\Admin\Resources\Threads;

use App\Enums\FeatureFlag;
use App\Filament\Admin\Resources\Threads\Pages\ListThreads;
use App\Filament\Admin\Resources\Threads\Pages\ViewThread;
use App\Filament\Admin\Resources\Threads\Tables\ThreadsTable;
use App\Models\Thread;
use App\Services\FeatureFlags\FeatureFlags;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Spec 13 Delivery > Messages: every project conversation (staff holding `threads.view`) and every support thread
 * (staff holding `support.view`), read and answered through the same Conversation component clients and contributors use.
 * The list is the policy's own `Thread::visibleTo`, so a role sees exactly the threads it may open.
 */
class ThreadResource extends Resource
{
    protected static ?string $model = Thread::class;

    protected static ?string $modelLabel = 'conversation';

    protected static ?string $navigationLabel = 'Messages';

    protected static string|UnitEnum|null $navigationGroup = 'Delivery';

    protected static ?int $navigationSort = 6;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->isAdmin()
            && app(FeatureFlags::class)->enabled(FeatureFlag::Messaging)
            && ($user->can('threads.view') || $user->can('support.view'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return ThreadsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->scopes(['visibleTo' => [$user], 'withUnread' => [$user]])
            ->with(['project:id,code,title', 'creator:id,name']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListThreads::route('/'),
            'view' => ViewThread::route('/{record}'),
        ];
    }
}
