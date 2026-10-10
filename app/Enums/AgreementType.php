<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** Spec 7.7. */
enum AgreementType: string implements HasLabel
{
    use LabelTrait;

    case MemberAgreement = 'member_agreement';
    case ClientMsa = 'client_msa';
    case SowTemplate = 'sow_template';
    case Privacy = 'privacy';
    case Terms = 'terms';
}
