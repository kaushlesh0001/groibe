<?php

namespace App\Enums;

use App\Enums\Concerns\HasLabel as LabelTrait;
use Filament\Support\Contracts\HasLabel;

/** What an import file holds (spec 15.1) and the permission needed to import it (section 5, SCR-031). */
enum ImportType: string implements HasLabel
{
    use LabelTrait;

    case Contributors = 'contributors';
    case Clients = 'clients';

    public function permission(): string
    {
        return match ($this) {
            self::Contributors => 'import_export.contributors',
            self::Clients => 'import_export.import_clients',
        };
    }
}
