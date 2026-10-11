<?php

namespace App\Filament\Admin\Resources\HomepageSections\Schemas;

use App\Filament\Admin\Support\NeedsReview;
use App\Models\CmsSection;
use App\Rules\PublicCopy;
use App\Support\Website\HomepageSections;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/** The section settings plus one JSON-backed content editor per section key (spec 10.1). */
class HomepageSectionForm
{
    private const URL_PATTERN = '/^(\/[A-Za-z0-9\-._~\/?#=&%]*|https:\/\/\S+)$/';

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Section')
                ->columns(2)
                ->schema([
                    Select::make('key')
                        ->options(fn (?CmsSection $record): array => self::availableKeys($record))
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated()
                        ->live(),
                    TextInput::make('title')->label('Admin title')->required()->maxLength(120),
                    TextInput::make('sort')->numeric()->required()->default(0)->helperText('Lower numbers show first. You can also drag rows on the list.'),
                    ...NeedsReview::toggles('Shown on the homepage'),
                ]),
            Section::make('Content')
                ->description('Text shown in this section. Leave a field empty to use the built-in wording.')
                ->schema([
                    Group::make(self::contentFields())->statePath('content'),
                ])
                ->visible(fn (Get $get): bool => filled($get('key'))),
        ]);
    }

    /** @return array<string, string> */
    private static function availableKeys(?CmsSection $record): array
    {
        $taken = CmsSection::query()->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->pluck('key')->all();

        return collect(HomepageSections::TITLES)->except($taken)->all();
    }

    /** @return list<Group> one per section key, each shown only for its own key */
    private static function contentFields(): array
    {
        $copy = fn (string $name, string $label, int $max = 160): TextInput => TextInput::make($name)->label($label)->maxLength($max)->rule(new PublicCopy);
        $text = fn (string $name, string $label, int $max = 400): Textarea => Textarea::make($name)->label($label)->rows(3)->maxLength($max)->rule(new PublicCopy);
        $link = fn (string $name, string $label): TextInput => TextInput::make($name)->label($label)->maxLength(255)->regex(self::URL_PATTERN)
            ->helperText('A path on this site such as /opportunities, or a full https:// address.');
        $limit = fn (): TextInput => TextInput::make('limit')->label('How many to show')->numeric()->minValue(1)->maxValue(12)->default(6);
        $steps = fn (string $name, string $label): Repeater => Repeater::make($name)->label($label)->defaultItems(0)->maxItems(8)->addActionLabel('Add step')
            ->schema([$copy('title', 'Step', 60)->required(), $text('text', 'Description', 240)])->columnSpanFull();
        $override = fn (string $name, string $label): TextInput => TextInput::make($name)->label($label)->numeric()->minValue(0)
            ->helperText('Leave empty to show the live count from the database.');

        $groups = [
            'hero' => [$copy('heading', 'Headline before the highlighted word', 80), $copy('highlight', 'Highlighted word', 40), $text('subheading', 'Line under the headline'),
                $copy('primary_label', 'Main button', 40), $link('primary_url', 'Main button link'), $copy('secondary_label', 'Second button', 40), $link('secondary_url', 'Second button link'),
                $copy('client_line', 'Line for clients', 120), $copy('client_link_label', 'Client link text', 60), $link('client_link_url', 'Client link')],
            'ecosystem' => [$copy('heading', 'Heading'), $text('subheading', 'Intro'), $copy('contributors_title', 'Contributors card title', 60), $text('contributors_text', 'Contributors card text'),
                $copy('clients_title', 'Clients card title', 60), $text('clients_text', 'Clients card text')],
            'how_it_works' => [$copy('heading', 'Heading'), $text('subheading', 'Intro'), $steps('contributor_steps', 'Steps for contributors'), $steps('client_steps', 'Steps for clients')],
            'open_roles' => [$copy('heading', 'Heading'), $text('intro', 'Intro'), $limit(), $copy('cta_label', 'Link text', 60)],
            'proof_of_work' => [$copy('heading', 'Heading'), $text('intro', 'Intro'), $limit(), $copy('cta_label', 'Link text', 60)],
            'community' => [$copy('heading', 'Heading'), $text('intro', 'Intro'), $limit(), $copy('cta_label', 'Link text', 60)],
            'organizations' => [$copy('heading', 'Heading'), $text('intro', 'Intro'), $limit(), $copy('cta_label', 'Link text', 60)],
            'stats' => [$copy('heading', 'Heading'), $override('override_contributors', 'Contributors'), $override('override_organizations', 'Organizations'), $override('override_projects', 'Projects delivered')],
            'testimonials' => [$copy('heading', 'Heading')],
            'faq' => [$copy('heading', 'Heading'), $text('intro', 'Intro')],
            'final_cta' => [$copy('heading', 'Heading'), $text('text', 'Text'), $copy('button_label', 'Button', 40), $link('button_url', 'Button link')],
        ];

        return collect($groups)
            ->map(fn (array $fields, string $key): Group => Group::make($fields)->columns(2)->visible(fn (Get $get): bool => $get('../key') === $key))
            ->values()
            ->all();
    }
}
