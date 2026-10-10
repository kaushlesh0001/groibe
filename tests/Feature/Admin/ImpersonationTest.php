<?php

use App\Actions\Admin\StartImpersonation;
use App\Actions\Admin\StopImpersonation;
use App\Enums\AdminRole;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Activitylog\Models\Activity;

it('lets a super admin start impersonation, switching the session user and logging it', function () {
    $admin = adminUser();
    $target = clientUser();

    $this->actingAs($admin);
    app(StartImpersonation::class)($admin, $target);

    expect(auth()->id())->toBe($target->id)
        ->and(session(StartImpersonation::SESSION_KEY))->toBe($admin->id);

    $log = Activity::query()->where('log_name', 'impersonation')->where('event', 'started')->sole();
    expect($log->causer_id)->toBe($admin->id)
        ->and($log->subject_id)->toBe($target->id);
});

it('shows the banner while impersonating and hides it afterwards', function () {
    $admin = adminUser();
    $target = clientUser();

    $this->actingAs($admin);
    app(StartImpersonation::class)($admin, $target);

    $this->get('/app/client')->assertOk()->assertSee('Impersonating '.$target->name, false)->assertSee('Stop');

    $this->post(route('impersonate.stop'))->assertRedirect('/admin');

    $this->get('/admin')->assertOk()->assertDontSee('Impersonating');
});

it('restores the admin and logs the stop', function () {
    $admin = adminUser();
    $target = contributorUser();

    $this->actingAs($admin);
    app(StartImpersonation::class)($admin, $target);
    app(StopImpersonation::class)();

    expect(auth()->id())->toBe($admin->id)
        ->and(session()->has(StartImpersonation::SESSION_KEY))->toBeFalse()
        ->and(Activity::query()->where('log_name', 'impersonation')->where('event', 'stopped')->count())->toBe(1);
});

it('denies impersonation to every non-super-admin', function (AdminRole $role) {
    $actor = adminUser($role);
    $this->actingAs($actor);

    expect(fn () => app(StartImpersonation::class)($actor, clientUser()))->toThrow(AuthorizationException::class);
    expect(Activity::query()->where('log_name', 'impersonation')->count())->toBe(0);
})->with([AdminRole::DeliveryManager, AdminRole::CommunityManager, AdminRole::FinanceOps]);

it('refuses to impersonate another super admin or oneself', function () {
    $admin = adminUser();
    $this->actingAs($admin);

    expect(fn () => app(StartImpersonation::class)($admin, adminUser()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(StartImpersonation::class)($admin, $admin))->toThrow(AuthorizationException::class);
});

it('refuses nested impersonation', function () {
    $admin = adminUser();
    $this->actingAs($admin);

    app(StartImpersonation::class)($admin, clientUser());

    expect(fn () => app(StartImpersonation::class)($admin, contributorUser()))->toThrow(LogicException::class);
});

it('does not let a normal user reach the stop route to gain admin access', function () {
    $this->actingAs(contributorUser())->post(route('impersonate.stop'))->assertForbidden();
});

it('sends guests away from the stop route', function () {
    $this->post(route('impersonate.stop'))->assertRedirect('/login');
});
