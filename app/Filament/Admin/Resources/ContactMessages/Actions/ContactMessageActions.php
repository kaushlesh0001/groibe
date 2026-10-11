<?php

namespace App\Filament\Admin\Resources\ContactMessages\Actions;

use App\Actions\Website\SetContactMessageStatus;
use App\Enums\ContactMessageStatus;
use App\Filament\Admin\Support\DomainActionRunner;
use App\Models\ContactMessage;
use Filament\Actions\Action;

/** Status buttons shared by the list rows and the message page. Each appears only where it changes something. */
class ContactMessageActions
{
    public static function markRead(): Action
    {
        return self::make('markRead', 'Mark read', ContactMessageStatus::Read, 'gray', 'Marked as read')
            ->visible(fn (ContactMessage $record): bool => $record->status === ContactMessageStatus::New && self::mayUpdate($record));
    }

    public static function markHandled(): Action
    {
        return self::make('markHandled', 'Mark handled', ContactMessageStatus::Handled, 'success', 'Marked as handled')
            ->visible(fn (ContactMessage $record): bool => $record->status !== ContactMessageStatus::Handled && self::mayUpdate($record));
    }

    public static function reopen(): Action
    {
        return self::make('reopen', 'Reopen', ContactMessageStatus::New, 'warning', 'Message reopened')
            ->visible(fn (ContactMessage $record): bool => $record->status === ContactMessageStatus::Handled && self::mayUpdate($record));
    }

    private static function make(string $name, string $label, ContactMessageStatus $to, string $color, string $done): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->requiresConfirmation()
            ->action(fn (ContactMessage $record) => DomainActionRunner::run(
                fn () => app(SetContactMessageStatus::class)($record, $to, auth()->user()),
                $done,
            ));
    }

    private static function mayUpdate(ContactMessage $record): bool
    {
        return auth()->user()?->can('update', $record) ?? false;
    }
}
