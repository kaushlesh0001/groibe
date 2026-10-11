<?php

namespace App\Filament\Admin\Resources\Pages\Schemas;

use App\Filament\Admin\Support\NeedsReview;
use App\Rules\PublicCopy;
use Closure;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required()->maxLength(160)->rule(new PublicCopy),
            TextInput::make('slug')
                ->required()
                ->maxLength(100)
                ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                ->unique(ignoreRecord: true)
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    if (in_array(strtolower((string) $value), config('groibe.cms.reserved_slugs', []), true)) {
                        $fail('That address is already used by the site. Choose another.');
                    }
                })
                ->disabledOn('edit')
                ->helperText('The address of the page: /your-slug. Lowercase letters, numbers and dashes. It cannot be changed after the page is created.'),
            RichEditor::make('body_html')->label('Content')->rule(new PublicCopy)->columnSpanFull(),
            TextInput::make('meta_title')->maxLength(70)->rule(new PublicCopy)->helperText('Shown in search results. Leave empty to use the title.'),
            Textarea::make('meta_description')->rows(2)->maxLength(320)->rule(new PublicCopy)->helperText('Shown in search results. Around 150 characters works best.'),
            ...NeedsReview::toggles('Published'),
        ])->columns(2);
    }
}
