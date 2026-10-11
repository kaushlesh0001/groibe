<?php

namespace App\Filament\Admin\Resources\Projects\RelationManagers;

use App\Enums\PayoutStatus;
use App\Models\Payout;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The project's payouts, read-only (spec 13). Finance acts on them from Finance > Payouts. Only staff holding `payouts.view` see this tab. */
class PayoutsRelationManager extends RelationManager
{
    protected static string $relationship = 'payouts';

    protected static ?string $title = 'Payouts';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = auth()->user();

        return $user !== null && $user->isAdmin() && $user->can('viewAny', Payout::class);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('user.name')->label('Contributor'),
                TextColumn::make('milestone.title')->label('Milestone')->placeholder('-')->limit(30),
                TextColumn::make('amount')->money('INR'),
                TextColumn::make('status')->badge()->color(fn (PayoutStatus $state): string => $state->color()),
                TextColumn::make('reference')->label('UTR')->placeholder('-'),
                TextColumn::make('paid_at')->label('Paid on')->date()->placeholder('-'),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user:id,name', 'milestone:id,title']))
            ->defaultSort('created_at', 'desc');
    }
}
