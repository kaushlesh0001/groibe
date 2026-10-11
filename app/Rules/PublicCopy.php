<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Spec 10.3 copy rules for anything an admin types onto a public page: no em dashes and no AI-related wording.
 * HTML tags are ignored, so it can check rich text too. `violation()` is also what the copy tests run over seeded content.
 */
class PublicCopy implements ValidationRule
{
    private const AI_WORDS = '/\b(AI|A\.I\.|artificial intelligence|machine learning|ChatGPT|GPT|LLMs?|chatbots?|generative|neural network)\b/iu';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $problem = self::violation(self::text($value));

        if ($problem !== null) {
            $fail("The :attribute {$problem}");
        }
    }

    /** The text of a value: a string as is, or every string inside an array such as a rich-text document. */
    private static function text(mixed $value): string
    {
        if (is_array($value)) {
            $parts = [];
            array_walk_recursive($value, function ($item) use (&$parts): void {
                if (is_string($item)) {
                    $parts[] = $item;
                }
            });

            return implode(' ', $parts);
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /** Null when the text is fine, otherwise a short reason. */
    public static function violation(string $text): ?string
    {
        $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (str_contains($plain, "\u{2014}") || str_contains($plain, '&mdash;')) {
            return 'must not contain an em dash. Use a comma, a colon or a full stop.';
        }

        if (preg_match(self::AI_WORDS, $plain) === 1) {
            return 'must not use AI-related wording on a public page.';
        }

        return null;
    }
}
