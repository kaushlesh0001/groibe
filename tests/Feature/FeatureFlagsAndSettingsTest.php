<?php

use App\Enums\FeatureFlag as Flag;
use App\Enums\SettingType;
use App\Models\FeatureFlag;
use App\Services\FeatureFlags\FeatureFlags;
use App\Services\Settings\Settings;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;

it('falls back to spec defaults when no row exists', function () {
    $flags = app(FeatureFlags::class);

    expect($flags->enabled(Flag::OpenRoles))->toBeTrue()
        ->and($flags->enabled(Flag::PublicClientReviews))->toBeFalse()
        ->and($flags->enabled('unknown_flag'))->toBeFalse();
});

it('toggles a flag and busts the cache', function () {
    $flags = app(FeatureFlags::class);

    expect($flags->enabled(Flag::Messaging))->toBeTrue();

    $flags->set(Flag::Messaging, false);
    expect($flags->enabled(Flag::Messaging))->toBeFalse()
        ->and($flags->disabled('messaging'))->toBeTrue();

    $flags->set(Flag::Messaging, true);
    expect($flags->enabled(Flag::Messaging))->toBeTrue();
});

it('busts the cache when a row is edited directly, as the admin panel does', function () {
    $flags = app(FeatureFlags::class);
    $flags->set(Flag::OpenRoles, true);
    expect($flags->enabled(Flag::OpenRoles))->toBeTrue();

    FeatureFlag::query()->where('key', 'open_roles')->firstOrFail()->update(['enabled' => false]);

    expect($flags->enabled(Flag::OpenRoles))->toBeFalse();
});

it('caches flag lookups instead of hitting the database each time', function () {
    $flags = app(FeatureFlags::class);
    $flags->set(Flag::OpenRoles, true);
    $flags->flush();

    $flags->enabled(Flag::OpenRoles);

    DB::enableQueryLog();
    $flags->enabled(Flag::OpenRoles);
    $flags->enabled(Flag::Messaging);

    expect(DB::getQueryLog())->toBeEmpty();
});

it('returns 404 from the feature middleware when off and passes when on', function () {
    Route::middleware(['web', 'feature:open_roles'])->get('/_probe', fn () => 'ok');

    $this->get('/_probe')->assertOk();

    app(FeatureFlags::class)->set(Flag::OpenRoles, false);
    $this->get('/_probe')->assertNotFound();
});

it('logs flag changes to the activity log', function () {
    app(FeatureFlags::class)->set(Flag::TwoFactorAdmin, true);
    app(FeatureFlags::class)->set(Flag::TwoFactorAdmin, false);

    expect(Activity::query()->where('subject_type', FeatureFlag::class)->count())->toBeGreaterThanOrEqual(2);
});

it('stores and decodes typed settings with a default', function () {
    $settings = app(Settings::class);

    $settings->set('site.tagline', 'Ideas find people.');
    $settings->set('stats.override', 42, SettingType::Integer);
    $settings->set('banner.on', true, SettingType::Boolean);
    $settings->set('home.order', ['hero', 'faq'], SettingType::Json);

    expect($settings->get('site.tagline'))->toBe('Ideas find people.')
        ->and($settings->get('stats.override'))->toBe(42)
        ->and($settings->get('banner.on'))->toBeTrue()
        ->and($settings->get('home.order'))->toBe(['hero', 'faq'])
        ->and($settings->get('missing', 'fallback'))->toBe('fallback');
});

it('refreshes cached settings after an update', function () {
    $settings = app(Settings::class);

    $settings->set('site.name', 'One');
    expect($settings->get('site.name'))->toBe('One');

    $settings->set('site.name', 'Two');
    expect($settings->get('site.name'))->toBe('Two');
});
