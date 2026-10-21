<?php

namespace App\Support\Seo;

/**
 * The JSON-LD behind a public page's structured data (spec 10.4). It is built here, not in a Blade file, because Blade reads
 * the text `@context` as its own directive and prints PHP in its place, which left the homepage data unreadable. The
 * `@context` key is added here, so a view passes only the type and its fields. `<`, `>` and `&` are escaped, so a title
 * can never close the script tag.
 */
class JsonLd
{
    public const CONTEXT = 'https://schema.org';

    /** @param  array<string, mixed>  $data */
    public static function json(array $data): string
    {
        return (string) json_encode(['@context' => self::CONTEXT, ...$data], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    }
}
