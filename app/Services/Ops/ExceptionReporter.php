<?php

namespace App\Services\Ops;

use App\Mail\ExceptionOccurred;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PDOException;
use Throwable;

/**
 * Emails an unhandled exception to the address in `GROIBE_EXCEPTION_EMAIL` (spec 17.4), throttled so a broken page that is
 * hit a hundred times sends one email, and a bad hour at most `exception_max_per_hour`. The full report stays in the log.
 *
 * The email names the exception class, the file and line, the request path and the time. It carries no trace, no query
 * string, no signed-in person, and no message for a database error, because that message holds the values of the query.
 * Reporting never throws: a mail failure is logged and swallowed, so it cannot hide the exception it was reporting.
 */
class ExceptionReporter
{
    private const MESSAGE_LIMIT = 300;

    public function report(Throwable $e): void
    {
        $to = config('groibe.ops.exception_email');

        if (! is_string($to) || trim($to) === '') {
            return;
        }

        try {
            if (! $this->mayMail($e)) {
                return;
            }

            Mail::to(trim($to))->send(new ExceptionOccurred($this->summary($e)));
        } catch (Throwable $failure) {
            Log::warning('The exception email could not be sent.', ['reason' => class_basename($failure)]);
        }
    }

    /** True when this exception is new within the throttle window and the hourly cap is not used up. */
    private function mayMail(Throwable $e): bool
    {
        $hourKey = 'groibe.exception.sent.'.now()->format('YmdH');

        if ((int) Cache::get($hourKey, 0) >= (int) config('groibe.ops.exception_max_per_hour')) {
            return false;
        }

        $fingerprint = 'groibe.exception.seen.'.sha1($e::class.'|'.$e->getFile().'|'.$e->getLine());
        $window = now()->addMinutes((int) config('groibe.ops.exception_throttle_minutes'));

        if (! Cache::add($fingerprint, true, $window)) {
            return false;
        }

        Cache::add($hourKey, 0, now()->addHour());
        Cache::increment($hourKey);

        return true;
    }

    /** @return array{class: string, message: string, where: string, request: string, environment: string, time: string} */
    private function summary(Throwable $e): array
    {
        // Path only: never the query string. A console run names the command and none of its arguments, which can be an email address.
        $request = app()->runningInConsole() && request()->route() === null
            ? 'console: '.(string) (((array) ($_SERVER['argv'] ?? []))[1] ?? 'unknown')
            : request()->method().' /'.ltrim(request()->path(), '/');

        return [
            'class' => $e::class,
            'message' => $this->message($e),
            'where' => $this->relative($e->getFile()).':'.$e->getLine(),
            'request' => $request,
            'environment' => (string) app()->environment(),
            'time' => now()->toDateTimeString().' '.config('app.timezone'),
        ];
    }

    private function message(Throwable $e): string
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof QueryException || $cause instanceof PDOException) {
                $state = $cause instanceof QueryException ? ($cause->errorInfo[0] ?? null) : $cause->getCode();

                return 'A database error (code '.(is_scalar($state) && $state !== '' ? $state : 'unknown').'). The details are in the log.';
            }
        }

        // The ellipsis counts toward the limit.
        return Str::limit(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', self::MESSAGE_LIMIT - 3);
    }

    private function relative(string $path): string
    {
        $base = str_replace('\\', '/', base_path()).'/';
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : basename($path);
    }
}
