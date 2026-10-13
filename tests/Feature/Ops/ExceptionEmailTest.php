<?php

use App\Mail\ExceptionOccurred;
use App\Services\Ops\ExceptionReporter;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Cache::flush();
    Mail::fake();
    config(['groibe.ops.exception_email' => 'ops@example.test']);
});

it('emails an exception to the ops address with its class, place and message', function () {
    app(ExceptionReporter::class)->report(new RuntimeException('Something broke'));

    Mail::assertSent(ExceptionOccurred::class, fn (ExceptionOccurred $mail): bool => $mail->hasTo('ops@example.test')
        && $mail->report['class'] === RuntimeException::class
        && $mail->report['message'] === 'Something broke'
        && str_contains($mail->report['where'], 'ExceptionEmailTest.php'));
});

it('sends nothing when no address is set', function () {
    config(['groibe.ops.exception_email' => null]);

    app(ExceptionReporter::class)->report(new RuntimeException('Something broke'));

    Mail::assertNothingSent();
});

it('mails the same exception only once in the throttle window, then again after it', function () {
    $raise = fn (): Throwable => new RuntimeException('Again');

    app(ExceptionReporter::class)->report($raise());
    app(ExceptionReporter::class)->report($raise());
    app(ExceptionReporter::class)->report($raise());

    Mail::assertSentCount(1);

    $this->travel(61)->minutes();

    app(ExceptionReporter::class)->report($raise());

    Mail::assertSentCount(2);
});

it('mails different exceptions separately, up to the hourly cap', function () {
    config(['groibe.ops.exception_max_per_hour' => 2]);

    app(ExceptionReporter::class)->report(new LogicException('one'));
    app(ExceptionReporter::class)->report(new DomainException('two'));
    app(ExceptionReporter::class)->report(new InvalidArgumentException('three'));

    Mail::assertSentCount(2);

    $this->travel(1)->hour();

    app(ExceptionReporter::class)->report(new OutOfRangeException('four'));

    Mail::assertSentCount(3);
});

it('never puts the values of a database query in the email', function () {
    $query = new QueryException('mysql', 'select * from users where email = ?', ['asha@example.test'], new PDOException('SQLSTATE[23000]'));

    // Blade and other wrappers carry the query message with them, so the cause is looked for down the chain.
    app(ExceptionReporter::class)->report(new RuntimeException('View failed: '.$query->getMessage(), 0, $query));

    Mail::assertSent(ExceptionOccurred::class, function (ExceptionOccurred $mail): bool {
        $text = $mail->render();

        return str_contains($mail->report['message'], 'database error')
            && ! str_contains($text, 'asha@example.test')
            && ! str_contains($text, 'select * from users');
    });
});

it('trims a long message and keeps it to one line', function () {
    app(ExceptionReporter::class)->report(new RuntimeException("line one\nline two ".str_repeat('x', 1000)));

    Mail::assertSent(ExceptionOccurred::class, fn (ExceptionOccurred $mail): bool => strlen($mail->report['message']) <= 300
        && ! str_contains($mail->report['message'], "\n"));
});

it('never lets a mail failure escape from the reporter', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp is down'));

    expect(fn () => app(ExceptionReporter::class)->report(new RuntimeException('Original problem')))->not->toThrow(Throwable::class);
});

it('is called for an unhandled error on a real request and leaves out the query string', function () {
    Route::get('/_ops-test-boom', fn () => throw new RuntimeException('Request failed'));

    $this->get('/_ops-test-boom?token=secret-token')->assertStatus(500);

    Mail::assertSent(ExceptionOccurred::class, function (ExceptionOccurred $mail): bool {
        $text = $mail->render();

        return $mail->report['request'] === 'GET /_ops-test-boom'
            && $mail->report['message'] === 'Request failed'
            && ! str_contains($text, 'secret-token');
    });
});

it('does not email for an error the app handles itself, such as a missing page', function () {
    $this->get('/this-page-does-not-exist-'.uniqid())->assertNotFound();

    Mail::assertNothingSent();
});

it('logs to the daily channel by default and keeps 14 days of files', function () {
    expect(file_get_contents(base_path('config/logging.php')))->toContain("env('LOG_CHANNEL', 'daily')")
        ->and(file_get_contents(base_path('.env.example')))->toContain('LOG_CHANNEL=daily')
        ->and(config('logging.channels.daily.days'))->toBe(14);
});
