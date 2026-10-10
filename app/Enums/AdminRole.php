<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

enum AdminRole: string implements HasLabel
{
    use LabelTrait;

    case SuperAdmin = 'super_admin';
    case DeliveryManager = 'delivery_manager';
    case CommunityManager = 'community_manager';
    case FinanceOps = 'finance_ops';
}
