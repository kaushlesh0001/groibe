<?php

namespace App\Filament\Admin\Resources\Deliverables;

use App\Enums\MilestoneStatus;
use App\Filament\Admin\Resources\Deliverables\Pages\ListDeliverables;
use App\Models\Deliverable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Spec 13 Delivery > Deliverables: every deliverable across projects, read-only. They are built on a
 * project's Deliverables tab, where the milestone and its approved work are in view.
 */
class DeliverableResource extends Resource
{
    protected static ?string $model = Deliverable::class;

    protected static ?string $slug = 'deliverables';

    protected static string|UnitEnum|null $navigationGroup = 'Delivery';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->isAdmin() && $user->can('viewAny', Deliverable::class);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('milestone.project.code')->label('Project'),
                TextColumn::make('milestone.title')->label('Milestone')->limit(30),
                TextColumn::make('milestone.status')
                    ->label('Milestone status')
                    ->badge()
                    ->color(fn (MilestoneStatus $state): string => $state->color()),
                TextColumn::make('title')->limit(40)->searchable(),
                TextColumn::make('submissions_count')->label('Approved items')->counts('submissions'),
                TextColumn::make('shared_at')->dateTime()->placeholder('Not shared')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                TernaryFilter::make('shared')->label('Shared with client')->nullable()->attribute('shared_at'),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['milestone:id,title,status,project_id', 'milestone.project:id,code']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeliverables::route('/'),
        ];
    }
}
