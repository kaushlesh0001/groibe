<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** Spec 2.2 and SCR-018. Set by an admin per role. */
enum OpenRoleVisibility: string implements HasLabel
{
    use LabelTrait;

    case InviteOnly = 'invite_only';
    case MembersOnly = 'members_only';
    case Public = 'public';

    public function getLabel(): string
    {
        return match ($this) {
            self::InviteOnly => 'Invite only',
            self::MembersOnly => 'Members only',
            self::Public => 'Public',
        };
    }
}
