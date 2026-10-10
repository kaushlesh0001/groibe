<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;

it('schedules the queue worker every minute for cron-driven hosting', function () {
    $events = collect(app(Schedule::class)->events());

    $worker = $events->first(fn ($event) => str_contains($event->command, 'queue:work'));

    expect($worker)->not->toBeNull()
        ->and($worker->command)->toContain('--stop-when-empty')->toContain('--max-time=50')
        ->and($worker->expression)->toBe('* * * * *');
});

it('prevents lazy loading outside production', function () {
    expect(Model::preventsLazyLoading())->toBeTrue();
});

it('ships shared-hosting defaults in .env.example', function () {
    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toContain('QUEUE_CONNECTION=database')
        ->toContain('SESSION_DRIVER=database')
        ->toContain('CACHE_STORE=file')
        ->toContain('LOG_CHANNEL=daily')
        ->not->toContain('REDIS_HOST=');
});

it('keeps real secrets out of the example env', function () {
    $env = file_get_contents(base_path('.env.example'));

    expect($env)->toMatch('/^ADMIN_EMAIL=$/m')->toMatch('/^ADMIN_PASSWORD=$/m');
});

it('makes no third-party font or script requests on public and auth pages', function (string $url) {
    $html = $this->get($url)->assertOk()->getContent();

    expect($html)->not->toMatch('~https?://(?!localhost)[^"\']*(bunny\.net|googleapis|gstatic|cdnjs|jsdelivr|unpkg|cloudflare)~i');
})->with(['/', '/login', '/register/contributor', '/register/client', '/forgot-password']);

it('defines the brand colour tokens', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('#042B65')->toContain('#0EAE44')->toContain('#25A446')->toContain('#046CEA')->toContain('#06A2A7')
        ->and($css)->toContain('@fontsource-variable/inter');
});

it('keeps public copy free of em dashes', function () {
    $home = $this->get('/')->getContent();

    expect($home)->not->toContain('—');
});

it('serves the health route', function () {
    $this->get('/up')->assertOk();
});
