<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** proof_of_work.visibility (spec 7.5). Only `public` items can reach /work, and only while published and consented. */
enum ProofVisibility: string implements HasLabel
{
    use LabelTrait;

    case Private = 'private';
    case Members = 'members';
    case Public = 'public';
}
