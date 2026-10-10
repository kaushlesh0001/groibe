<?php

namespace App\Enums\Concerns;

use Illuminate\Support\Str;

trait HasLabel
{
    public function getLabel(): string
    {
        return Str::headline($this->name);
    }
}
