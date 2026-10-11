<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/**
 * threads.type (spec 7.8). `application` is listed by the spec but nothing in section 14 uses it, so v1 never creates one.
 */
enum ThreadType: string implements HasLabel
{
    use LabelTrait;

    case Project = 'project';
    case Support = 'support';
    case Application = 'application';

    public function color(): string
    {
        return match ($this) {
            self::Project => 'primary',
            self::Support => 'warning',
            self::Application => 'gray',
        };
    }
}
