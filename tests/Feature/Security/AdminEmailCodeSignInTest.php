<?php

use App\Enums\AdminRole;
use App\Enums\FeatureFlag;
use App\Models\User;
use App\Notifications\AdminSignInCode;
use App\Services\Auth\AdminEmailCodeAuthentication;
use App\Services\FeatureFlags\FeatureFlags;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * SCR-040: signing in to /admin/login with the emailed code, through the real sign-in page. The rules of the code itself are in
 * AdminEmailCodeTest.
 */

const SIGN_IN_PASSWORD = 'password';

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    app(FeatureFlags::class)->set(FeatureFlag::TwoFactorAdmin, true);
});

/** An admin with no authenticator app, so the email code is the only second step. */
function signInAdmin(AdminRole $role = AdminRole::SuperAdmin): User
{
    return User::factory()->admin($role)->withoutTwoFactor()->create();
}

/** The password step. Returns the page, and puts the code that was emailed in `$sentCode`. */
function enterPassword(User $user, ?string &$sentCode = null): Testable
{
    $sentCode = null;
    $page = Livewire::test(Login::class)
        ->set('data.email', $user->email)
        ->set('data.password', SIGN_IN_PASSWORD)
        ->call('authenticate');

    Notification::assertSentTo($user, AdminSignInCode::class, function (AdminSignInCode $notice) use (&$sentCode): bool {
        $sentCode = $notice->code;

        return true;
    });

    return $page;
}

function submitCode(Testable $page, string $code): Testable
{
    return $page->set('data.multiFactor.email_code.code', $code)->call('authenticate');
}

function signInWrong(string $real): string
{
    return $real === '000000' ? '111111' : '000000';
}

describe('signing in', function () {
    it('asks for the emailed code after the password, then lets the admin in', function () {
        Notification::fake();
        $admin = signInAdmin();

        $page = enterPassword($admin, $code);

        expect($code)->toMatch('/^\d{6}$/')
            ->and($page->get('userUndertakingMultiFactorAuthentication'))->not->toBeNull();
        $this->assertGuest();

        submitCode($page, $code);

        $this->assertAuthenticatedAs($admin);
    });

    it('does not let an admin in with the password alone', function () {
        Notification::fake();
        $admin = signInAdmin();

        enterPassword($admin);

        $this->assertGuest();
    });

    it('refuses a wrong code and stays signed out', function () {
        Notification::fake();
        $page = enterPassword(signInAdmin(), $code);

        submitCode($page, signInWrong($code))->assertHasErrors(['data.multiFactor.email_code.code']);

        $this->assertGuest();
    });

    it('refuses an expired code', function () {
        Notification::fake();
        $page = enterPassword(signInAdmin(), $code);

        $this->travel(11)->minutes();
        submitCode($page, $code)->assertHasErrors(['data.multiFactor.email_code.code']);

        $this->assertGuest();
    });

    it('accepts a code nine minutes old', function () {
        Notification::fake();
        $admin = signInAdmin();
        $page = enterPassword($admin, $code);

        $this->travel(9)->minutes();
        submitCode($page, $code);

        $this->assertAuthenticatedAs($admin);
    });

    it('refuses a code that was already used', function () {
        Notification::fake();
        $admin = signInAdmin();
        $first = enterPassword($admin, $code);
        submitCode($first, $code);
        $this->assertAuthenticatedAs($admin);

        Auth::logout();
        $this->travel(61)->seconds();
        Notification::fake();
        $second = enterPassword($admin, $fresh);

        if ($fresh === $code) {
            $this->markTestSkipped('The two random codes happened to match.');
        }

        submitCode($second, $code)->assertHasErrors(['data.multiFactor.email_code.code']);
        $this->assertGuest();
    });

    /*
     * Filament also limits the sign-in page itself to 5 calls a minute (the password step counts as one), so a person can make at
     * most four code tries a minute through the page. The lockout counts wrong codes for the person across minutes, so the tries are
     * spaced here: three, a minute's wait, then two.
     */
    it('locks the admin out after five wrong codes, and the right code no longer works', function () {
        Notification::fake();
        $admin = signInAdmin();
        $page = enterPassword($admin, $code);

        foreach (range(1, 3) as $try) {
            submitCode($page, signInWrong($code));
        }
        expect(AdminEmailCodeAuthentication::make()->isLockedOut($admin))->toBeFalse();

        $this->travel(61)->seconds();
        foreach (range(1, 2) as $try) {
            submitCode($page, signInWrong($code));
        }

        expect(AdminEmailCodeAuthentication::make()->isLockedOut($admin))->toBeTrue();

        $this->travel(61)->seconds();
        submitCode($page, $code)->assertHasErrors(['data.multiFactor.email_code.code']);
        $this->assertGuest();
    });

    it('lets a locked-out admin in again after fifteen minutes with a fresh code', function () {
        Notification::fake();
        $admin = signInAdmin();
        $page = enterPassword($admin, $code);
        foreach (range(1, 3) as $try) {
            submitCode($page, signInWrong($code));
        }
        $this->travel(61)->seconds();
        foreach (range(1, 2) as $try) {
            submitCode($page, signInWrong($code));
        }
        expect(AdminEmailCodeAuthentication::make()->isLockedOut($admin))->toBeTrue();

        $this->travel(16)->minutes();
        expect(AdminEmailCodeAuthentication::make()->isLockedOut($admin))->toBeFalse();

        Notification::fake();
        $again = enterPassword($admin, $fresh);
        submitCode($again, $fresh);

        $this->assertAuthenticatedAs($admin);
    });
});

describe('who gets a code', function () {
    it('sends nothing for a wrong password, so a stranger cannot make the panel email an admin', function () {
        Notification::fake();
        $admin = signInAdmin();

        $page = Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'not-the-password')
            ->call('authenticate');

        Notification::assertNothingSent();
        expect($page->get('userUndertakingMultiFactorAuthentication'))->toBeNull();
        $this->assertGuest();
    });

    it('sends nothing for an email nobody has', function () {
        Notification::fake();

        Livewire::test(Login::class)->set('data.email', 'nobody@example.com')->set('data.password', SIGN_IN_PASSWORD)->call('authenticate');

        Notification::assertNothingSent();
        $this->assertGuest();
    });

    it('sends nothing to a contributor or a client who knows their password, and shows no code page', function (string $kind) {
        Notification::fake();
        $person = $kind === 'contributor' ? contributorUser() : clientUser();

        $page = Livewire::test(Login::class)->set('data.email', $person->email)->set('data.password', SIGN_IN_PASSWORD)->call('authenticate');

        Notification::assertNothingSent();
        expect($page->get('userUndertakingMultiFactorAuthentication'))->toBeNull();
        $this->assertGuest();
    })->with(['contributor', 'client']);

    it('does not open the set-up page to a guest or to a non-admin', function () {
        $url = Filament::getSetUpRequiredMultiFactorAuthenticationUrl();

        $this->get($url)->assertRedirect();
        asUser(contributorUser())->get($url)->assertForbidden();
        asUser(clientUser())->get($url)->assertForbidden();
    });

    it('asks every other admin role for a code while the flag is on', function (AdminRole $role) {
        Notification::fake();
        $admin = signInAdmin($role);

        $page = enterPassword($admin, $code);
        submitCode($page, $code);

        $this->assertAuthenticatedAs($admin);
    })->with([AdminRole::DeliveryManager, AdminRole::CommunityManager, AdminRole::FinanceOps]);

    it('lets every other admin role in with the password alone while the flag is off', function (AdminRole $role) {
        app(FeatureFlags::class)->set(FeatureFlag::TwoFactorAdmin, false);
        Notification::fake();
        $admin = signInAdmin($role);

        Livewire::test(Login::class)->set('data.email', $admin->email)->set('data.password', SIGN_IN_PASSWORD)->call('authenticate');

        Notification::assertNothingSent();
        $this->assertAuthenticatedAs($admin);
    })->with([AdminRole::DeliveryManager, AdminRole::CommunityManager, AdminRole::FinanceOps]);

    it('still asks a super admin for a code while the flag is off', function () {
        app(FeatureFlags::class)->set(FeatureFlag::TwoFactorAdmin, false);
        Notification::fake();
        $admin = signInAdmin();

        $page = enterPassword($admin, $code);
        $this->assertGuest();

        submitCode($page, $code);
        $this->assertAuthenticatedAs($admin);
    });
});
