<?php

namespace App\Filament\Admin\Resources\Testimonials\Schemas;

use App\Filament\Admin\Support\NeedsReview;
use App\Rules\PublicCopy;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TestimonialForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('author_name')->required()->maxLength(120),
            TextInput::make('author_title')->maxLength(120),
            TextInput::make('organization_name')->maxLength(160),
            TextInput::make('sort')->numeric()->required()->default(0)->helperText('Lower numbers show first.'),
            Textarea::make('quote')->required()->rows(4)->maxLength(600)->rule(new PublicCopy)
                ->helperText('Only a real quote that the person has agreed to share. Do not edit their words.')->columnSpanFull(),
            ...NeedsReview::toggles('Shown on the homepage'),
        ])->columns(2);
    }
}
