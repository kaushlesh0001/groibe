<?php

namespace App\Filament\Admin\Resources\ContactMessages\Schemas;

use App\Models\ContactMessage;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContactMessageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Message')
                ->columns(2)
                ->schema([
                    TextEntry::make('name'),
                    TextEntry::make('email')->copyable(),
                    TextEntry::make('subject')->placeholder('No subject'),
                    TextEntry::make('status')->badge()->color(fn (ContactMessage $record): string => $record->status->color()),
                    TextEntry::make('message')->columnSpanFull()->prose(),
                ]),
            Section::make('Details')
                ->columns(2)
                ->collapsed()
                ->schema([
                    TextEntry::make('created_at')->label('Received')->dateTime(),
                    TextEntry::make('email_sent_at')->label('Emailed to the team')->dateTime()->placeholder('Not sent'),
                    TextEntry::make('handler.name')->label('Handled by')->placeholder('-'),
                    TextEntry::make('handled_at')->dateTime()->placeholder('-'),
                    TextEntry::make('ip')->label('IP address')->placeholder('-'),
                    TextEntry::make('user_agent')->label('Browser')->placeholder('-'),
                ]),
        ]);
    }
}
