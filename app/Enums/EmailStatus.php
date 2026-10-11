<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** email_log.status (spec 7.8). */
enum EmailStatus: string implements HasLabel
{
    use LabelTrait;

    case Sent = 'sent';
    case Failed = 'failed';

    public function color(): string
    {
        return match ($this) {
            self::Sent => 'success',
            self::Failed => 'danger',
        };
    }
}
