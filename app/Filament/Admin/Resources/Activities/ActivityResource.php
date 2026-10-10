<?php

namespace App\Filament\Admin\Resources\Activities;

use App\Filament\Admin\Resources\Activities\Pages\ListActivities;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

/**
 * Read-only audit log (spec 13, 17.2). Super admin sees everything; other admins see only their own actions.
 */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'audit log entry';

    protected static ?string $pluralModelLabel = 'audit log';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('causer');
        $viewer = auth()->user();

        if ($viewer === null || ! $viewer->can('audit_log.view_all')) {
            $query->where('causer_type', $viewer?->getMorphClass())->where('causer_id', $viewer?->getKey());
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('log_name')->label('Log')->badge()->sortable(),
                TextColumn::make('event')->badge()->sortable(),
                TextColumn::make('description')->searchable()->wrap(),
                TextColumn::make('causer.name')->label('By')->placeholder('System'),
                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->formatStateUsing(fn (?string $state, Activity $record): string => $state
                        ? class_basename($state).' #'.$record->subject_id
                        : '-'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('log_name')
                    ->label('Log')
                    ->options(fn (): array => Activity::query()->distinct()->pluck('log_name', 'log_name')->all()),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
        ];
    }
}
