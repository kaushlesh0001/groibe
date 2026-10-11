<?php

namespace App\Filament\Admin\Resources\EmailLogs;

use App\Enums\EmailStatus;
use App\Filament\Admin\Resources\EmailLogs\Pages\ListEmailLogs;
use App\Models\EmailLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Spec 13 System > Email Log. Read-only evidence of every email the app sent or failed to send (spec 14), for super
 * admins only. It holds recipient addresses and an error, never the message body.
 */
class EmailLogResource extends Resource
{
    protected static ?string $model = EmailLog::class;

    protected static ?string $modelLabel = 'email';

    protected static ?string $navigationLabel = 'Email Log';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user:id,name');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('status')->badge()->color(fn (EmailStatus $state): string => $state->color())->sortable(),
                TextColumn::make('template')->searchable(),
                TextColumn::make('to')->label('To')->searchable(),
                TextColumn::make('user.name')->label('User')->placeholder('-')->toggleable(),
                TextColumn::make('error')->limit(80)->tooltip(fn (EmailLog $record): ?string => $record->error)->placeholder('-'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(EmailStatus::class)->default(EmailStatus::Failed->value),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmailLogs::route('/'),
        ];
    }
}
