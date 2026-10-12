<?php

use App\Actions\Admin\StartImpersonation;
use App\Enums\OnboardingState;
use App\Enums\UserStatus;
use Illuminate\Auth\Access\AuthorizationException;

/*
 * Spec 4 and 14: a suspended person loses access at once. Sign-in refuses a suspended account, but a session that was already open
 * must stop working on the next request too. Found by the route audit: a suspended client kept using the whole client app.
 *
 * Contributors are different on purpose (spec 14, and the tests for them in ContributorAccessTest): a suspended contributor keeps a
 * status page, their dashboard and profile and the support thread, and loses every page that handles work, money or applications.
 */

it('signs a suspended client out on their next request and sends them to sign in', function (string $url) {
    $client = clientUser();
    $this->actingAs($client);
    $this->get('/app/client')->assertOk();

    $client->forceFill(['status' => UserStatus::Suspended])->save();

    $this->get($url)->assertRedirect(route('login'));
    $this->assertGuest();
    $this->get('/app/client')->assertRedirect(route('login'));
})->with(['/app/client', '/app/client/briefs', '/app/client/projects', '/app/client/messages', '/notifications', '/settings/profile', '/']);

it('tells them why on the sign-in page', function () {
    $client = clientUser();
    $client->forceFill(['status' => UserStatus::Suspended])->save();

    $this->actingAs($client)->get('/app/client')->assertRedirect(route('login'));

    $this->get('/login')->assertSee('This account is suspended');
});

it('refuses a Livewire request from a suspended client with a plain 403, not a page', function () {
    $client = clientUser();
    $this->actingAs($client);

    $client->forceFill(['status' => UserStatus::Suspended])->save();

    $this->withHeaders(['X-Livewire' => 'true'])->post(route('livewire.update'), [])->assertForbidden();
    $this->assertGuest();
});

it('signs a suspended admin out of the public site too, and the admin panel still refuses them', function () {
    $admin = adminUser();
    $this->actingAs($admin);
    $this->get('/admin')->assertOk();

    $admin->forceFill(['status' => UserStatus::Suspended])->save();

    // The panel has its own stack and refuses a suspended admin itself; the public site's stack signs them out.
    $this->get('/admin')->assertForbidden();
    $this->get('/notifications')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('leaves a suspended contributor their limited view, and closes the work pages', function () {
    $contributor = contributorIn(OnboardingState::Active);
    $contributor->forceFill(['status' => UserStatus::Suspended])->save();
    $this->actingAs($contributor);

    $this->get('/app/contributor')->assertOk();
    $this->get('/app/contributor/profile')->assertOk();

    // The status page (403) answers before any record is looked up, so a made-up project id gets 403 as well.
    foreach (['/app/contributor/discover', '/app/contributor/applications', '/app/contributor/work/projects/1'] as $url) {
        expect($this->get($url)->status())->toBeIn([403, 404]);
    }

    $this->get('/app/contributor/discover')->assertForbidden();
});

it('lets active and pending people through as before', function () {
    $this->actingAs(contributorIn(OnboardingState::Active))->get('/app/contributor')->assertOk();
    asUser(contributorIn(OnboardingState::PendingReview))->get('/app/contributor')->assertOk();
    asUser(clientUser())->get('/app/client')->assertOk();
});

it('keeps impersonation to active accounts, and ends it if the client is suspended meanwhile', function () {
    $admin = adminUser();
    $target = clientUser();

    $this->actingAs($admin);
    app(StartImpersonation::class)($admin, $target);
    $this->get('/app/client')->assertOk();

    $target->forceFill(['status' => UserStatus::Suspended])->save();

    $this->get('/app/client')->assertRedirect(route('login'));
    $this->assertGuest();

    // An account that is already suspended cannot be impersonated at all (the policy, unchanged).
    asUser($admin);
    expect(fn () => app(StartImpersonation::class)($admin, $target))->toThrow(AuthorizationException::class);
});

it('does nothing for a visitor', function () {
    $this->get('/')->assertOk();
    $this->get('/app/client')->assertRedirect(route('login'));
});
