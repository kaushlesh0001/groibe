<?php

use App\Models\User;
use Livewire\Volt\Volt;

it('sends guests to login for every app route', function (string $url) {
    $this->get($url)->assertRedirect('/login');
})->with(['/app/contributor', '/app/client', '/dashboard']);

it('sends guests to the admin login for the admin panel', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('lets a contributor into the contributor app only', function () {
    $user = contributorUser();

    $this->actingAs($user)->get('/app/contributor')->assertOk();
    $this->actingAs($user)->get('/app/client')->assertForbidden();
    $this->actingAs($user)->get('/admin')->assertForbidden();
});

it('lets a client into the client app only', function () {
    $user = clientUser();

    $this->actingAs($user)->get('/app/client')->assertOk();
    $this->actingAs($user)->get('/app/contributor')->assertForbidden();
    $this->actingAs($user)->get('/admin')->assertForbidden();
});

it('lets an admin into the admin panel but not the user apps', function () {
    $admin = adminUser();

    $this->actingAs($admin)->get('/admin')->assertOk();
    $this->actingAs($admin)->get('/app/contributor')->assertForbidden();
    $this->actingAs($admin)->get('/app/client')->assertForbidden();
});

it('blocks suspended admins from the panel', function () {
    $admin = User::factory()->admin()->suspended()->create();

    $this->actingAs($admin)->get('/admin')->assertForbidden();
});

it('requires a verified email for the apps', function () {
    $user = User::factory()->contributor()->unverified()->create();

    $this->actingAs($user)->get('/app/contributor')->assertRedirect('/verify-email');
});

it('redirects /dashboard to the app for each role', function () {
    $this->actingAs(contributorUser())->get('/dashboard')->assertRedirect('/app/contributor');
    $this->actingAs(clientUser())->get('/dashboard')->assertRedirect('/app/client');
    $this->actingAs(adminUser())->get('/dashboard')->assertRedirect('/admin');
});

it('lands each role on an empty dashboard after login', function () {
    $contributor = contributorUser();

    Volt::test('auth.login')
        ->set('email', $contributor->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->get('/app/contributor')->assertOk()->assertSee('Welcome');
});

it('refuses login for suspended users and records last login for others', function () {
    $suspended = User::factory()->contributor()->suspended()->create();

    Volt::test('auth.login')
        ->set('email', $suspended->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();

    $active = contributorUser();

    Volt::test('auth.login')
        ->set('email', $active->email)
        ->set('password', 'password')
        ->call('login');

    expect($active->fresh()->last_login_at)->not->toBeNull();
});

it('locks login after five failed attempts', function () {
    $user = contributorUser();

    foreach (range(1, 5) as $i) {
        Volt::test('auth.login')->set('email', $user->email)->set('password', 'wrong')->call('login');
    }

    Volt::test('auth.login')
        ->set('email', $user->email)
        ->set('password', 'password')
        ->call('login')
        ->assertHasErrors('email');

    $this->assertGuest();
});
