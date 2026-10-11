<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** contact_messages.status (SCR-021). A simple inbox: new, read, handled. Any move between them is allowed. */
enum ContactMessageStatus: string implements HasLabel
{
    use LabelTrait;

    case New = 'new';
    case Read = 'read';
    case Handled = 'handled';

    public function color(): string
    {
        return match ($this) {
            self::New => 'warning',
            self::Read => 'gray',
            self::Handled => 'success',
        };
    }
}
