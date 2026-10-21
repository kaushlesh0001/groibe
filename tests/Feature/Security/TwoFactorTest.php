<?php

use App\Enums\AdminRole;
use App\Enums\FeatureFlag;
use App\Models\User;
use App\Services\FeatureFlags\FeatureFlags;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;

/** An admin of the given kind who has not set up two-factor authentication. */
function adminWithoutTwoFactor(AdminRole $role = AdminRole::SuperAdmin): User
{
    return User::factory()->admin($role)->withoutTwoFactor()->create();
}

function setTwoFactorFlag(bool $on): void
{
    app(FeatureFlags::class)->set(FeatureFlag::TwoFactorAdmin, $on);
}

describe('who must have a second step (SCR-040: the emailed code, nothing to set up)', function () {
    it('lets a super admin without an authenticator app in, whatever the flag says, because the emailed code is theirs by role', function (bool $flag) {
        setTwoFactorFlag($flag);

        $this->actingAs(adminWithoutTwoFactor())->get('/admin')->assertOk();
    })->with([true, false]);

    it('lets every other admin role without an app in while the flag is on, and while it is off', function (AdminRole $role, bool $flag) {
        setTwoFactorFlag($flag);

        $this->actingAs(adminWithoutTwoFactor($role))->get('/admin')->assertOk();
    })->with([AdminRole::DeliveryManager, AdminRole::CommunityManager, AdminRole::FinanceOps])->with([true, false]);

    it('requires it by default, as spec 18 lists', function () {
        expect(app(FeatureFlags::class)->enabled(FeatureFlag::TwoFactorAdmin))->toBeTrue();
    });

    it('lets an admin who has set up the app in', function (AdminRole $role) {
        $this->actingAs(adminUser($role))->get('/admin')->assertOk();
    })->with(AdminRole::cases());

    it('is a guard on every admin page: a required admin the panel offers no second step is sent to set-up', function () {
        setTwoFactorFlag(true);
        // A panel that, by mistake, had no provider enabled for anyone.
        app()->bind(MultiFactorChallenge::class, fn () => new class extends MultiFactorChallenge
        {
            public function hasEnabledProviders(Authenticatable $user): bool
            {
                return false;
            }
        });

        $this->actingAs(adminWithoutTwoFactor());

        foreach (['/admin', '/admin/users', '/admin/organizations', '/admin/imports', '/admin/payouts'] as $url) {
            $this->get($url)->assertRedirectContains('multi-factor-authentication/set-up');
        }
    });

    it('does not send an admin who is not required to have it to set-up, even if the panel offers nothing', function () {
        setTwoFactorFlag(false);
        app()->bind(MultiFactorChallenge::class, fn () => new class extends MultiFactorChallenge
        {
            public function hasEnabledProviders(Authenticatable $user): bool
            {
                return false;
            }
        });

        $this->actingAs(adminWithoutTwoFactor(AdminRole::FinanceOps))->get('/admin')->assertOk();
    });

    it('leaves guests and non-admins as they were', function () {
        $this->get('/admin')->assertRedirect();
        asUser(contributorUser())->get('/admin')->assertForbidden();
        asUser(clientUser())->get('/admin')->assertForbidden();
    });
});

describe('the panel setup', function () {
    it('has the emailed code first, then authenticator-app 2FA with recovery codes, marked required', function () {
        $panel = Filament::getPanel('admin');
        $providers = $panel->getMultiFactorAuthenticationProviders();

        expect($panel->isMultiFactorAuthenticationRequired())->toBeTrue()
            ->and(array_keys($providers))->toBe(['email_code', 'app'])
            ->and($providers['app'])->toBeInstanceOf(AppAuthentication::class)
            ->and($providers['app']->isRecoverable())->toBeTrue()
            ->and($panel->hasProfile())->toBeTrue();
    });

    it('challenges every admin who must have the emailed code, and an admin who is not required only if they set up the app', function () {
        setTwoFactorFlag(false);
        $challenge = MultiFactorChallenge::make();

        expect($challenge->hasEnabledProviders(adminWithoutTwoFactor()))->toBeTrue()                       // super admin: the emailed code
            ->and($challenge->hasEnabledProviders(adminUser(AdminRole::DeliveryManager)))->toBeTrue()       // has the app
            ->and($challenge->hasEnabledProviders(adminWithoutTwoFactor(AdminRole::DeliveryManager)))->toBeFalse(); // flag off, no app
    });
});
describe('codes and recovery codes', function () {
    it('accepts a current code and refuses a wrong one', function () {
        $provider = AppAuthentication::make()->recoverable();
        $admin = adminWithoutTwoFactor();
        $secret = $provider->generateSecret();
        $provider->saveSecret($admin, $secret);

        expect($provider->verifyCode($provider->getCurrentCode($admin->refresh()), $secret))->toBeTrue()
            ->and($provider->verifyCode('000000', $secret))->toBeFalse();
    });

    it('accepts each recovery code once', function () {
        $provider = AppAuthentication::make()->recoverable();
        $admin = adminWithoutTwoFactor();
        $provider->saveSecret($admin, $provider->generateSecret());
        $codes = $provider->generateRecoveryCodes();
        $provider->saveRecoveryCodes($admin, $codes);

        $admin->refresh();
        expect($provider->verifyRecoveryCode($codes[0], $admin))->toBeTrue()
            ->and($provider->verifyRecoveryCode($codes[0], $admin->refresh()))->toBeFalse()
            ->and($provider->verifyRecoveryCode($codes[1], $admin->refresh()))->toBeTrue()
            ->and($provider->verifyRecoveryCode('not-a-code', $admin->refresh()))->toBeFalse();
    });

    it('keeps the secret encrypted and the recovery codes hashed and encrypted, and both out of JSON', function () {
        $provider = AppAuthentication::make()->recoverable();
        $admin = adminWithoutTwoFactor();
        $provider->saveSecret($admin, 'JBSWY3DPEHPK3PXP');
        $provider->saveRecoveryCodes($admin, ['AAAA-1111', 'BBBB-2222']);

        $row = (array) DB::table('users')->where('id', $admin->id)->first();

        expect($row['two_factor_secret'])->not->toContain('JBSWY3DPEHPK3PXP')
            ->and($row['two_factor_recovery_codes'])->not->toContain('AAAA-1111')
            ->and($admin->refresh()->two_factor_secret)->toBe('JBSWY3DPEHPK3PXP')
            // Filament hashes each code before it is stored, so even the decrypted value is not a code anyone could type.
            ->and($admin->two_factor_recovery_codes)->toHaveCount(2)->not->toContain('AAAA-1111')->not->toContain('BBBB-2222')
            ->and(json_encode($admin->toArray()))->not->toContain('JBSWY3DPEHPK3PXP')->not->toContain('AAAA-1111');
    });

    it('writes turning it on and off to the audit log without the secret or the codes', function () {
        $admin = adminWithoutTwoFactor();

        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $admin->saveAppAuthenticationRecoveryCodes(['AAAA-1111']);
        $admin->refresh()->saveAppAuthenticationSecret(null);

        $events = Activity::query()->where('log_name', 'auth')->whereIn('event', ['two_factor_enabled', 'two_factor_disabled'])->orderBy('id')->get();

        expect($events->pluck('event')->all())->toBe(['two_factor_enabled', 'two_factor_disabled'])
            ->and($events->every(fn (Activity $entry) => $entry->causer_id === $admin->id))->toBeTrue()
            ->and(json_encode($events->toArray()))->not->toContain('JBSWY3DPEHPK3PXP')->not->toContain('AAAA-1111');
    });

    it('removes the recovery codes when it is turned off', function () {
        $admin = adminUser();
        $admin->saveAppAuthenticationRecoveryCodes(['AAAA-1111']);

        $admin->refresh()->saveAppAuthenticationSecret(null);

        expect($admin->refresh()->two_factor_recovery_codes)->toBeNull()->and($admin->two_factor_confirmed_at)->toBeNull()->and($admin->hasTwoFactor())->toBeFalse();
    });
});

describe('the recovery command', function () {
    it('turns it off for one person by email and writes an audit entry', function () {
        $admin = adminUser();
        $admin->saveAppAuthenticationRecoveryCodes(['AAAA-1111']);
        $other = adminUser(AdminRole::DeliveryManager);

        $this->artisan('groibe:two-factor-reset', ['email' => strtoupper($admin->email), '--force' => true])
            ->expectsOutputToContain('are off for')
            ->assertSuccessful();

        $admin->refresh();
        expect([$admin->two_factor_secret, $admin->two_factor_recovery_codes, $admin->two_factor_confirmed_at])->toBe([null, null, null])
            ->and($other->refresh()->hasTwoFactor())->toBeTrue()
            // The emailed code is required by role, so a reset cannot switch it off.
            ->and($admin->hasEmailAuthentication())->toBeTrue();

        $entry = Activity::query()->where('event', 'two_factor_reset')->sole();
        expect($entry->subject_id)->toBe($admin->id)->and($entry->causer_id)->toBeNull()->and($entry->properties['email'])->toBe($admin->email)
            ->and(json_encode($entry->properties))->not->toContain('AAAA-1111');
    });

    it('leaves the person signing in with the emailed code, with nothing to set up again', function () {
        $admin = adminUser();

        $this->artisan('groibe:two-factor-reset', ['email' => $admin->email, '--force' => true])->assertSuccessful();

        $this->actingAs($admin->refresh())->get('/admin')->assertOk();
        expect(MultiFactorChallenge::make()->hasEnabledProviders($admin))->toBeTrue()
            ->and(MultiFactorChallenge::make()->getFirstEnabledProvider($admin)->getId())->toBe('email_code');
    });

    it('asks first, and changes nothing when the answer is no', function () {
        $admin = adminUser();

        $this->artisan('groibe:two-factor-reset', ['email' => $admin->email])
            ->expectsConfirmation("Turn off the authenticator app and recovery codes for {$admin->email} (admin)?", 'no')
            ->expectsOutputToContain('Nothing was changed')
            ->assertSuccessful();

        expect($admin->refresh()->hasTwoFactor())->toBeTrue()
            ->and(Activity::query()->where('event', 'two_factor_reset')->exists())->toBeFalse();
    });

    it('fails clearly for an email nobody has, and does nothing for someone without it', function () {
        $this->artisan('groibe:two-factor-reset', ['email' => 'nobody@example.com', '--force' => true])
            ->expectsOutputToContain('No user has the email')
            ->assertFailed();

        $this->artisan('groibe:two-factor-reset', ['email' => adminWithoutTwoFactor()->email, '--force' => true])
            ->expectsOutputToContain('Nothing was changed')
            ->assertSuccessful();

        expect(Activity::query()->where('event', 'two_factor_reset')->exists())->toBeFalse();
    });

    it('is not reachable from the web', function () {
        $admin = adminUser();

        expect(collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->uri(), 'two-factor-reset')))->toBeEmpty();
    });
});
