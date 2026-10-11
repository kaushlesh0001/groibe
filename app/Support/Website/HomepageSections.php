<?php

namespace App\Support\Website;

/**
 * The eleven homepage sections of spec 10.1, in their default order. The key is what the homepage view looks up,
 * the title is what Admin sees, and `defaults()` is the content shape each section understands. Admins edit the
 * content, order and visibility; they cannot invent a section the page has no view for.
 */
class HomepageSections
{
    /** @var array<string, string> key => admin title, in the spec's order */
    public const TITLES = [
        'hero' => 'Hero',
        'ecosystem' => 'Ecosystem',
        'how_it_works' => 'How it works',
        'open_roles' => 'Live open roles',
        'proof_of_work' => 'Proof of work showcase',
        'community' => 'Community',
        'organizations' => 'Organizations',
        'stats' => 'Stats',
        'testimonials' => 'Testimonials',
        'faq' => 'FAQ',
        'final_cta' => 'Final call to action',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::TITLES);
    }

    public static function title(string $key): string
    {
        return self::TITLES[$key] ?? $key;
    }

    /** Sections that list live records and show a "limit" for how many. @return list<string> */
    public static function listing(): array
    {
        return ['open_roles', 'proof_of_work', 'community', 'organizations'];
    }

    /** Sections that are driven by data and hide on their own when it is empty or the flag is off. @return list<string> */
    public static function dataDriven(): array
    {
        return ['open_roles', 'proof_of_work', 'community', 'organizations', 'stats', 'testimonials', 'faq'];
    }
}
