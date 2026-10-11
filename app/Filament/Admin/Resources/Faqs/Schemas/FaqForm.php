<?php

namespace App\Filament\Admin\Resources\Faqs\Schemas;

use App\Enums\FaqAudience;
use App\Filament\Admin\Support\NeedsReview;
use App\Rules\PublicCopy;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class FaqForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('audience')->options(FaqAudience::class)->required()->default(FaqAudience::General->value),
            TextInput::make('sort')->numeric()->required()->default(0)->helperText('Lower numbers show first within the audience.'),
            TextInput::make('question')->required()->maxLength(255)->rule(new PublicCopy)->columnSpanFull(),
            Textarea::make('answer')->required()->rows(6)->maxLength(3000)->rule(new PublicCopy)->helperText('Plain text. Line breaks are kept.')->columnSpanFull(),
            ...NeedsReview::toggles('Shown on the site'),
        ])->columns(2);
    }
}
