<?php

namespace App\Support;

use App\Exceptions\ActionBlocked;

/** Money checks for the finance Actions. Amounts stay strings with two decimals, never floats (spec 7). */
class Money
{
    private const MAX = '999999999999.99';

    /** @throws ActionBlocked when the value is not a positive amount with at most two decimals */
    public static function positive(mixed $value, string $label = 'The amount'): string
    {
        $amount = self::parse($value, $label);

        if (bccomp($amount, '0', 2) <= 0) {
            throw ActionBlocked::guardFailed("{$label} must be more than zero.");
        }

        return $amount;
    }

    /** @throws ActionBlocked when the value is not zero or a positive amount with at most two decimals */
    public static function nonNegative(mixed $value, string $label = 'The amount'): string
    {
        return self::parse($value, $label);
    }

    private static function parse(mixed $value, string $label): string
    {
        $text = is_string($value) ? trim($value) : (is_int($value) || is_float($value) ? (string) $value : '');

        if (! preg_match('/^\d{1,12}(\.\d{1,2})?$/', $text) || bccomp($text, self::MAX, 2) > 0) {
            throw ActionBlocked::guardFailed("{$label} must be a number with up to two decimals, for example 12500 or 12500.50.");
        }

        return bcadd($text, '0', 2);
    }
}
