<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A small rate limit for the sign-in and account screens that are Livewire components, where a route throttle cannot tell one
 * person's attempts from another's (spec 17.2). Each attempt counts against a scope and a subject, such as a visitor's address
 * or a signed-in user's id, and the limit applies once the count reaches the maximum inside the window.
 */
final class Throttle
{
    /**
     * Counts one attempt, and refuses it with a message on the given field when there have been too many.
     *
     * @throws ValidationException
     */
    public static function guard(string $scope, string $subject, int $maxAttempts, int $decaySeconds, string $field): void
    {
        $key = self::key($scope, $subject);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ValidationException::withMessages([
                $field => __('Too many attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    /** Counts one attempt and says whether it may go ahead, for places that must not show an error (such as a resend button). */
    public static function allow(string $scope, string $subject, int $maxAttempts, int $decaySeconds): bool
    {
        $key = self::key($scope, $subject);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return false;
        }

        RateLimiter::hit($key, $decaySeconds);

        return true;
    }

    /** Forgets the count, after a success. */
    public static function clear(string $scope, string $subject): void
    {
        RateLimiter::clear(self::key($scope, $subject));
    }

    private static function key(string $scope, string $subject): string
    {
        return $scope.'|'.sha1(Str::lower($subject));
    }
}
