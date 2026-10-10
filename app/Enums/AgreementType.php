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

    /** Plain-language note for admins: which screen or step reads this template (spec 8, 12). */
    public function usedAt(): string
    {
        return match ($this) {
            self::MemberAgreement => 'Contributor onboarding, step 4. A contributor must accept the current version before they can become active.',
            self::ClientMsa => 'Client app, "Client MSA" page. The organization owner must accept it before the first brief or SOW acceptance.',
            self::SowTemplate => 'Every new SOW. The variables are filled from the project and the result is saved on the SOW, so later edits here never change a sent SOW.',
            self::Privacy => 'Public Privacy page (/privacy). That page is not built yet, so nothing shows this text today.',
            self::Terms => 'Public Terms page (/terms). That page is not built yet, so nothing shows this text today.',
        };
    }
}
