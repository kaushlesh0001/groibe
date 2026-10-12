<?php

use App\Enums\AdminRole;
use App\Enums\FeatureFlag;
use App\Models\User;
use App\Services\FeatureFlags\FeatureFlags;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Facades\Filament;
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

describe('who must set it up', function () {
    it('sends a super admin without it to the set-up page, whatever the flag says', function (bool $flag) {
        setTwoFactorFlag($flag);

        $this->actingAs(adminWithoutTwoFactor())->get('/admin')
            ->assertRedirect()
            ->assertRedirectContains('multi-factor-authentication/set-up');
    })->with([true, false]);

    it('sends every other admin role without it to set-up while the flag is on', function (AdminRole $role) {
        setTwoFactorFlag(true);

        $this->actingAs(adminWithoutTwoFactor($role))->get('/admin')->assertRedirectContains('multi-factor-authentication/set-up');
    })->with([AdminRole::DeliveryManager, AdminRole::CommunityManager, AdminRole::FinanceOps]);

    it('lets the other admin roles in without it when the flag is off', function (AdminRole $role) {
        setTwoFactorFlag(false);

        $this->actingAs(adminWithoutTwoFactor($role))->get('/admin')->assertOk();
    })->with([AdminRole::DeliveryManager, AdminRole::CommunityManager, AdminRole::FinanceOps]);

    it('requires it by default, as spec 18 lists', function () {
        expect(app(FeatureFlags::class)->enabled(FeatureFlag::TwoFactorAdmin))->toBeTrue();
    });

    it('lets an admin who has set it up in', function (AdminRole $role) {
        $this->actingAs(adminUser($role))->get('/admin')->assertOk();
    })->with(AdminRole::cases());

    it('lets an admin without it open the set-up page, so there is no redirect loop', function () {
        $admin = adminWithoutTwoFactor();
        $url = $this->actingAs($admin)->get('/admin')->headers->get('Location');

        $this->get($url)->assertOk();
    });

    it('blocks every admin page for them, not only the dashboard', function () {
        $this->actingAs(adminWithoutTwoFactor());

        foreach (['/admin/users', '/admin/organizations', '/admin/imports', '/admin/payouts'] as $url) {
            $this->get($url)->assertRedirectContains('multi-factor-authentication/set-up');
        }
    });

    it('leaves guests and non-admins as they were', function () {
        $this->get('/admin')->assertRedirect();
        asUser(contributorUser())->get('/admin')->assertForbidden();
        asUser(clientUser())->get('/admin')->assertForbidden();
    });
});

describe('the panel setup', function () {
    it('has authenticator-app 2FA with recovery codes, marked required', function () {
        $panel = Filament::getPanel('admin');
        $providers = $panel->getMultiFactorAuthenticationProviders();

        expect($panel->isMultiFactorAuthenticationRequired())->toBeTrue()
            ->and($providers)->toHaveCount(1)
            ->and($providers['app'])->toBeInstanceOf(AppAuthentication::class)
            ->and($providers['app']->isRecoverable())->toBeTrue()
            ->and($panel->hasProfile())->toBeTrue();
    });

    it('challenges anyone who has set it up, and nobody who has not', function () {
        $with = adminUser();
        $without = adminWithoutTwoFactor();

        expect(MultiFactorChallenge::make()->hasEnabledProviders($with))->toBeTrue()
            ->and(MultiFactorChallenge::make()->hasEnabledProviders($without))->toBeFalse();
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
            ->expectsOutputToContain('is off for')
            ->assertSuccessful();

        $admin->refresh();
        expect([$admin->two_factor_secret, $admin->two_factor_recovery_codes, $admin->two_factor_confirmed_at])->toBe([null, null, null])
            ->and($other->refresh()->hasTwoFactor())->toBeTrue();

        $entry = Activity::query()->where('event', 'two_factor_reset')->sole();
        expect($entry->subject_id)->toBe($admin->id)->and($entry->causer_id)->toBeNull()->and($entry->properties['email'])->toBe($admin->email)
            ->and(json_encode($entry->properties))->not->toContain('AAAA-1111');
    });

    it('sends the person through set-up again at their next admin visit', function () {
        $admin = adminUser();

        $this->artisan('groibe:two-factor-reset', ['email' => $admin->email, '--force' => true])->assertSuccessful();

        $this->actingAs($admin->refresh())->get('/admin')->assertRedirectContains('multi-factor-authentication/set-up');
    });

    it('asks first, and changes nothing when the answer is no', function () {
        $admin = adminUser();

        $this->artisan('groibe:two-factor-reset', ['email' => $admin->email])
            ->expectsConfirmation("Turn off two-factor authentication for {$admin->email} (admin)?", 'no')
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
