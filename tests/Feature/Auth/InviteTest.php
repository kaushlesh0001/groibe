<?php

use App\Actions\Onboarding\SendInvite;
use App\Enums\UserStatus;
use App\Exceptions\IllegalTransition;
use App\Models\User;
use App\Notifications\InviteNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Spatie\Activitylog\Models\Activity;

function inviteUrl(User $user, int $days = 7): string
{
    return URL::temporarySignedRoute('invite.show', now()->addDays($days), ['token' => $user->uuid]);
}

it('sends a signed invite to a pending user without a password and logs it', function () {
    Notification::fake();
    $admin = adminUser();
    $user = User::factory()->client()->invited()->create();

    $url = app(SendInvite::class)($user, $admin);

    Notification::assertSentTo($user, InviteNotification::class, fn ($n) => $n->url === $url);
    expect($url)->toContain('signature=')
        ->and(Activity::query()->where('event', 'invite_sent')->where('causer_id', $admin->id)->exists())->toBeTrue();
});

it('refuses to invite users who are already active or have a password', function () {
    expect(fn () => app(SendInvite::class)(User::factory()->client()->create()))
        ->toThrow(IllegalTransition::class);
});

it('renders the activation page for a valid signed link', function () {
    $user = User::factory()->client()->invited()->create();

    $this->get(inviteUrl($user))->assertOk()->assertSee('Activate your account');
});

it('rejects unsigned, tampered and expired links', function () {
    $user = User::factory()->client()->invited()->create();

    $this->get('/invite/'.$user->uuid)->assertForbidden();
    $this->get(inviteUrl($user).'x')->assertForbidden();
    $this->get(inviteUrl($user, -1))->assertForbidden();
});

it('returns 404 for an unknown token with a valid signature', function () {
    $this->get(URL::temporarySignedRoute('invite.show', now()->addDay(), ['token' => 'nope']))->assertNotFound();
});

it('activates a client: sets password, verifies email, marks active and signs in', function () {
    $user = User::factory()->client()->invited()->create();

    Volt::test('auth.invite', ['token' => $user->uuid])
        ->set('password', 'a-long-password')
        ->set('password_confirmation', 'a-long-password')
        ->call('activate')
        ->assertHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $user->refresh();

    expect($user->status)->toBe(UserStatus::Active)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->not->toBeNull();

    $this->assertAuthenticatedAs($user);
});

it('keeps an invited contributor pending until admin review', function () {
    $user = User::factory()->contributor()->invited()->create();

    Volt::test('auth.invite', ['token' => $user->uuid])
        ->set('password', 'a-long-password')
        ->set('password_confirmation', 'a-long-password')
        ->call('activate');

    expect($user->fresh()->status)->toBe(UserStatus::Pending)
        ->and($user->fresh()->email_verified_at)->not->toBeNull();
});

it('cannot redeem an invite twice', function () {
    $user = User::factory()->client()->invited()->create();

    Volt::test('auth.invite', ['token' => $user->uuid])
        ->set('password', 'a-long-password')
        ->set('password_confirmation', 'a-long-password')
        ->call('activate');

    auth()->logout();

    $this->get(inviteUrl($user))->assertNotFound();
});

it('enforces password rules on activation', function () {
    $user = User::factory()->client()->invited()->create();

    Volt::test('auth.invite', ['token' => $user->uuid])
        ->set('password', 'short')
        ->set('password_confirmation', 'short')
        ->call('activate')
        ->assertHasErrors('password');

    expect($user->fresh()->password)->toBeNull();
});
