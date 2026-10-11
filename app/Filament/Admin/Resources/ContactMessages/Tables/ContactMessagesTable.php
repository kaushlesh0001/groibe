<?php

namespace App\Filament\Admin\Resources\ContactMessages\Tables;

use App\Enums\ContactMessageStatus;
use App\Filament\Admin\Resources\ContactMessages\Actions\ContactMessageActions;
use App\Models\ContactMessage;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ContactMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('subject')->limit(50)->placeholder('No subject'),
                TextColumn::make('status')->badge()->color(fn (ContactMessage $record): string => $record->status->color())->sortable(),
                TextColumn::make('created_at')->label('Received')->dateTime()->sortable(),
            ])
            ->filters([SelectFilter::make('status')->options(ContactMessageStatus::class)->default(ContactMessageStatus::New->value)])
            ->recordActions([
                ViewAction::make(),
                ContactMessageActions::markRead(),
                ContactMessageActions::markHandled(),
                ContactMessageActions::reopen(),
            ]);
    }
}
