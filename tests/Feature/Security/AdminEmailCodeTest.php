<?php

use App\Enums\AdminRole;
use App\Enums\FeatureFlag;
use App\Models\EmailLog;
use App\Models\User;
use App\Notifications\AdminSignInCode;
use App\Services\Auth\AdminEmailCodeAuthentication;
use App\Services\FeatureFlags\FeatureFlags;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Spatie\Activitylog\Models\Activity;

/*
 * SCR-040: the emailed sign-in code for the admin panel. These tests cover the provider (the rules) here; the sign-in page itself
 * is in AdminEmailCodeSignInTest.
 */

function codeAdmin(AdminRole $role = AdminRole::SuperAdmin): User
{
    return User::factory()->admin($role)->withoutTwoFactor()->create();
}

function codeFlag(bool $on): void
{
    app(FeatureFlags::class)->set(FeatureFlag::TwoFactorAdmin, $on);
}

function codeProvider(): AdminEmailCodeAuthentication
{
    return AdminEmailCodeAuthentication::make();
}

/** Sends a code with notifications faked and returns it. */
function sendAndCapture(User $admin): string
{
    Notification::fake();
    expect(codeProvider()->sendCode($admin))->toBeTrue();

    $code = null;
    Notification::assertSentTo($admin, AdminSignInCode::class, function (AdminSignInCode $notice) use (&$code): bool {
        $code = $notice->code;

        return true;
    });

    return (string) $code;
}

/** A six-digit code that is not the real one. */
function wrongCode(string $real): string
{
    return $real === '000000' ? '111111' : '000000';
}

function codeCacheValue(User $admin): mixed
{
    $method = new ReflectionMethod(codeProvider(), 'getCodeCacheKey');

    return Cache::get($method->invoke(codeProvider(), $admin));
}

describe('who has it', function () {
    it('is always on for a super admin, whatever the flag says', function (bool $flag) {
        codeFlag($flag);
        $admin = codeAdmin();

        expect($admin->mustUseEmailCode())->toBeTrue()
            ->and($admin->hasEmailAuthentication())->toBeTrue()
            ->and(codeProvider()->isEnabled($admin))->toBeTrue();
    })->with([true, false]);

    it('is on for every other admin role while two_factor_admin is on', function (AdminRole $role) {
        codeFlag(true);

        expect(codeProvider()->isEnabled(codeAdmin($role)))->toBeTrue();
    })->with([AdminRole::DeliveryManager, AdminRole::CommunityManager, AdminRole::FinanceOps]);

    it('is off for every other admin role while two_factor_admin is off', function (AdminRole $role) {
        codeFlag(false);
        $admin = codeAdmin($role);

        expect(codeProvider()->isEnabled($admin))->toBeFalse()
            ->and($admin->mustUseEmailCode())->toBeFalse();
    })->with([AdminRole::DeliveryManager, AdminRole::CommunityManager, AdminRole::FinanceOps]);

    it('follows the flag when it is switched, with nothing to set up', function () {
        $admin = codeAdmin(AdminRole::FinanceOps);

        codeFlag(false);
        expect(codeProvider()->isEnabled($admin))->toBeFalse();

        codeFlag(true);
        expect(codeProvider()->isEnabled($admin))->toBeTrue();
    });

    it('is never on for a contributor or a client', function () {
        codeFlag(true);

        expect(codeProvider()->isEnabled(contributorUser()))->toBeFalse()
            ->and(codeProvider()->isEnabled(clientUser()))->toBeFalse();
    });

    it('cannot be switched on or off by the person', function () {
        codeFlag(false);
        $manager = codeAdmin(AdminRole::DeliveryManager);
        $manager->toggleEmailAuthentication(true);
        expect(codeProvider()->isEnabled($manager))->toBeFalse();

        $super = codeAdmin();
        $super->toggleEmailAuthentication(false);
        expect(codeProvider()->isEnabled($super))->toBeTrue();
    });

    it('is the first method on the panel, with the authenticator app and its recovery codes kept after it', function () {
        $providers = Filament\Facades\Filament::getPanel('admin')->getMultiFactorAuthenticationProviders();

        expect(array_keys($providers))->toBe(['email_code', 'app'])
            ->and($providers['email_code'])->toBeInstanceOf(AdminEmailCodeAuthentication::class)
            ->and($providers['app']->isRecoverable())->toBeTrue();
    });
});

describe('the code and the email', function () {
    it('is six digits, valid for ten minutes, and sent to the admin\'s own address', function () {
        $admin = codeAdmin();
        $other = codeAdmin(AdminRole::DeliveryManager);

        $code = sendAndCapture($admin);

        expect($code)->toMatch('/^\d{6}$/')
            ->and(config('groibe.admin_email_code.expires_minutes'))->toBe(10)
            ->and(codeProvider()->getCodeExpiryMinutes())->toBe(10);

        Notification::assertSentTo($admin, AdminSignInCode::class, fn (AdminSignInCode $n): bool => $n->codeExpiryMinutes === 10);
        Notification::assertNotSentTo($other, AdminSignInCode::class);
        Notification::assertCount(1);
    });

    it('goes by email only, and to the address on the account', function () {
        $admin = codeAdmin();
        Notification::fake();
        codeProvider()->sendCode($admin);

        Notification::assertSentTo($admin, AdminSignInCode::class, function (AdminSignInCode $notice, array $channels) use ($admin): bool {
            return $channels === ['mail'] && $admin->routeNotificationFor('mail') === $admin->email;
        });
    });

    it('is sent at once and never queued', function () {
        Queue::fake();
        $admin = codeAdmin();

        expect(codeProvider()->sendCode($admin))->toBeTrue();

        Queue::assertNothingPushed();
        expect(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and(is_subclass_of(AdminSignInCode::class, ShouldQueue::class))->toBeFalse()
            ->and(codeProvider()->getCodeNotification())->toBe(AdminSignInCode::class);
    });

    it('arrives as a real email with the code in it', function () {
        $admin = codeAdmin();

        codeProvider()->sendCode($admin);

        $message = collect(app('mailer')->getSymfonyTransport()->messages())->last()?->getOriginalMessage();

        expect($message)->not->toBeNull()
            ->and($message->getSubject())->toBe('Your Groibe admin sign-in code')
            ->and($message->getTo()[0]->getAddress())->toBe($admin->email)
            ->and($message->getHtmlBody())->toMatch('/\b\d{6}\b/')
            ->and($message->getHtmlBody())->toContain('10 minutes');
    });

    it('is stored hashed, never as the code', function () {
        $admin = codeAdmin();
        $code = sendAndCapture($admin);

        $stored = codeCacheValue($admin);

        expect($stored)->toBeArray()
            ->and(json_encode($stored))->not->toContain($code)
            ->and(Hash::check($code, $stored['hash']))->toBeTrue()
            ->and(Hash::isHashed($stored['hash']))->toBeTrue();
    });
});

describe('using the code', function () {
    it('accepts the right code', function () {
        $admin = codeAdmin();
        $code = sendAndCapture($admin);

        expect(codeProvider()->verifyCode($code, $admin))->toBeTrue();
    });

    it('refuses a wrong code', function () {
        $admin = codeAdmin();
        $code = sendAndCapture($admin);

        expect(codeProvider()->verifyCode(wrongCode($code), $admin))->toBeFalse()
            // The right one still works: one wrong try does not use it up.
            ->and(codeProvider()->verifyCode($code, $admin))->toBeTrue();
    });

    it('accepts a code nine minutes old', function () {
        $admin = codeAdmin();
        $code = sendAndCapture($admin);

        $this->travel(9)->minutes();

        expect(codeProvider()->verifyCode($code, $admin))->toBeTrue();
    });

    it('refuses a code eleven minutes old', function () {
        $admin = codeAdmin();
        $code = sendAndCapture($admin);

        $this->travel(11)->minutes();

        expect(codeProvider()->verifyCode($code, $admin))->toBeFalse();
    });

    it('works once: the same code is refused the second time', function () {
        $admin = codeAdmin();
        $code = sendAndCapture($admin);

        expect(codeProvider()->verifyCode($code, $admin))->toBeTrue()
            ->and(codeProvider()->verifyCode($code, $admin))->toBeFalse()
            ->and(codeCacheValue($admin))->toBeNull();
    });

    it('is ended by a newer code: the old one no longer works', function () {
        $admin = codeAdmin();
        $old = sendAndCapture($admin);
        $this->travel(61)->seconds();
        $new = sendAndCapture($admin);

        // The odds that two random codes match are one in a million; make sure the test is meaningful.
        if ($old === $new) {
            $this->markTestSkipped('The two random codes happened to match.');
        }

        expect(codeProvider()->verifyCode($old, $admin))->toBeFalse()
            ->and(codeProvider()->verifyCode($new, $admin))->toBeTrue();
    });

    it('does not accept one admin\'s code for another admin', function () {
        $a = codeAdmin();
        $b = codeAdmin(AdminRole::DeliveryManager);
        $codeA = sendAndCapture($a);

        expect(codeProvider()->verifyCode($codeA, $b))->toBeFalse();
    });

    it('refuses everything when no code was sent', function () {
        expect(codeProvider()->verifyCode('123456', codeAdmin()))->toBeFalse();
    });
});

describe('asking for another code', function () {
    it('allows one email a minute, the first one included', function () {
        $admin = codeAdmin();
        Notification::fake();

        expect(codeProvider()->sendCode($admin))->toBeTrue()
            ->and(codeProvider()->sendCode($admin))->toBeFalse();

        Notification::assertCount(1);

        $this->travel(59)->seconds();
        expect(codeProvider()->sendCode($admin))->toBeFalse();
        Notification::assertCount(1);

        $this->travel(2)->seconds();
        expect(codeProvider()->sendCode($admin))->toBeTrue();
        Notification::assertCount(2);
    });

    it('limits each person on their own', function () {
        $a = codeAdmin();
        $b = codeAdmin(AdminRole::DeliveryManager);
        Notification::fake();

        expect(codeProvider()->sendCode($a))->toBeTrue()
            ->and(codeProvider()->sendCode($b))->toBeTrue();
    });
});

describe('wrong codes and the lockout', function () {
    it('locks the person out after five wrong codes, even for the right one', function () {
        $admin = codeAdmin();
        $code = sendAndCapture($admin);
        $wrong = wrongCode($code);

        foreach (range(1, 4) as $try) {
            expect(codeProvider()->verifyCode($wrong, $admin))->toBeFalse()
                ->and(codeProvider()->isLockedOut($admin))->toBeFalse();
        }

        expect(codeProvider()->verifyCode($wrong, $admin))->toBeFalse()
            ->and(codeProvider()->isLockedOut($admin))->toBeTrue()
            ->and(codeProvider()->verifyCode($code, $admin))->toBeFalse();
    });

    it('lasts fifteen minutes', function () {
        $admin = codeAdmin();
        $code = sendAndCapture($admin);
        foreach (range(1, 5) as $try) {
            codeProvider()->verifyCode(wrongCode($code), $admin);
        }

        $this->travel(14)->minutes();
        expect(codeProvider()->isLockedOut($admin))->toBeTrue();

        $this->travel(2)->minutes();
        expect(codeProvider()->isLockedOut($admin))->toBeFalse();
    });

    it('ends the code that was open, so a new one is needed after it', function () {
        $admin = codeAdmin();
        $code = sendAndCapture($admin);
        foreach (range(1, 5) as $try) {
            codeProvider()->verifyCode(wrongCode($code), $admin);
        }

        expect(codeCacheValue($admin))->toBeNull();

        $this->travel(16)->minutes();
        expect(codeProvider()->verifyCode($code, $admin))->toBeFalse();

        $fresh = sendAndCapture($admin);
        expect(codeProvider()->verifyCode($fresh, $admin))->toBeTrue();
    });

    it('sends no code while locked out', function () {
        $admin = codeAdmin();
        $code = sendAndCapture($admin);
        foreach (range(1, 5) as $try) {
            codeProvider()->verifyCode(wrongCode($code), $admin);
        }
        RateLimiter::clear('groibe-admin-email-code-send:'.hash('sha256', 'admin-email-code|'.Filament\Facades\Filament::getUserScopedAuthIdentifier($admin)));

        Notification::fake();
        expect(codeProvider()->sendCode($admin))->toBeFalse();
        Notification::assertNothingSent();
    });

    it('counts across sessions, so signing in again does not start the count again', function () {
        $admin = codeAdmin();
        $code = sendAndCapture($admin);

        foreach (range(1, 3) as $try) {
            codeProvider()->verifyCode(wrongCode($code), $admin);
        }

        session()->regenerate();

        foreach (range(1, 2) as $try) {
            codeProvider()->verifyCode(wrongCode($code), $admin);
        }

        expect(codeProvider()->isLockedOut($admin))->toBeTrue();
    });

    it('starts the count again after a right code', function () {
        $admin = codeAdmin();
        $code = sendAndCapture($admin);
        foreach (range(1, 4) as $try) {
            codeProvider()->verifyCode(wrongCode($code), $admin);
        }
        expect(codeProvider()->verifyCode($code, $admin))->toBeTrue();

        $this->travel(61)->seconds();
        $next = sendAndCapture($admin);
        foreach (range(1, 4) as $try) {
            codeProvider()->verifyCode(wrongCode($next), $admin);
        }

        expect(codeProvider()->isLockedOut($admin))->toBeFalse();
    });

    it('locks one person only', function () {
        $a = codeAdmin();
        $b = codeAdmin(AdminRole::DeliveryManager);
        $code = sendAndCapture($a);
        foreach (range(1, 5) as $try) {
            codeProvider()->verifyCode(wrongCode($code), $a);
        }

        expect(codeProvider()->isLockedOut($a))->toBeTrue()
            ->and(codeProvider()->isLockedOut($b))->toBeFalse();
    });

    it('writes the lockout to the audit log with no code in it', function () {
        $admin = codeAdmin();
        $code = sendAndCapture($admin);
        foreach (range(1, 5) as $try) {
            codeProvider()->verifyCode(wrongCode($code), $admin);
        }

        $entry = Activity::query()->where('event', 'email_code_lockout')->sole();

        expect($entry->subject_id)->toBe($admin->id)
            ->and($entry->properties['minutes'])->toBe(15)
            ->and(json_encode($entry->toArray()))->not->toContain($code);
    });
});

describe('where the code never appears', function () {
    it('is not in the email log, the audit log, the cache or the queue tables, and the email log keeps only the class and address', function () {
        $admin = codeAdmin();
        Activity::query()->delete();

        codeProvider()->sendCode($admin);

        $message = collect(app('mailer')->getSymfonyTransport()->messages())->last()->getOriginalMessage();
        preg_match('/\b(\d{6})\b/', (string) $message->getHtmlBody(), $found);
        $code = $found[1];

        // Wrong codes and a right one, so the log of those attempts is checked too.
        codeProvider()->verifyCode(wrongCode($code), $admin);
        codeProvider()->verifyCode($code, $admin);

        $emailLog = json_encode(EmailLog::query()->get()->toArray());
        $audit = json_encode(Activity::query()->get()->toArray());

        expect(EmailLog::query()->where('template', 'AdminSignInCode')->where('to', $admin->email)->count())->toBe(1)
            ->and($emailLog)->not->toContain($code)
            ->and($audit)->not->toContain($code)
            ->and(json_encode(DB::table('jobs')->get()))->not->toContain($code)
            ->and(json_encode(DB::table('failed_jobs')->get()))->not->toContain($code)
            ->and(json_encode(DB::table('notifications')->get()))->not->toContain($code);
    });

    it('is kept out of stack traces by the notification\'s constructor', function () {
        $constructor = new ReflectionMethod(AdminSignInCode::class, '__construct');
        $attributes = array_map(fn (ReflectionAttribute $a): string => $a->getName(), $constructor->getParameters()[0]->getAttributes());

        expect($attributes)->toContain(SensitiveParameter::class);

        $verify = new ReflectionMethod(AdminEmailCodeAuthentication::class, 'verifyCode');
        $verifyAttributes = array_map(fn (ReflectionAttribute $a): string => $a->getName(), $verify->getParameters()[0]->getAttributes());

        expect($verifyAttributes)->toContain(SensitiveParameter::class);
    });
});

describe('when the email cannot be sent', function () {
    it('reports the problem, keeps no usable code, and does not break the page', function () {
        $admin = codeAdmin();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);
        app('mail.manager')->purge();

        expect(codeProvider()->sendCode($admin))->toBeFalse()
            ->and(codeCacheValue($admin))->toBeNull();
    });
});

describe('the recovery command and the code', function () {
    it('clears a lockout and the resend wait, and leaves the emailed code switched on', function () {
        $admin = codeAdmin();
        $code = sendAndCapture($admin);
        foreach (range(1, 5) as $try) {
            codeProvider()->verifyCode(wrongCode($code), $admin);
        }
        expect(codeProvider()->isLockedOut($admin))->toBeTrue();

        $this->artisan('groibe:two-factor-reset', ['email' => $admin->email, '--force' => true])
            ->expectsOutputToContain('lockout')
            ->assertSuccessful();

        expect(codeProvider()->isLockedOut($admin))->toBeFalse()
            ->and(codeProvider()->isEnabled($admin))->toBeTrue();

        Notification::fake();
        expect(codeProvider()->sendCode($admin))->toBeTrue();
    });
});

describe('the profile page note', function () {
    it('says the code is required for a super admin and not required when the flag is off', function () {
        $super = codeAdmin();
        $manager = codeAdmin(AdminRole::DeliveryManager);
        codeFlag(false);

        $this->actingAs($super);
        $text = json_encode(array_map(fn ($component) => $component->getContent(), codeProvider()->getManagementSchemaComponents()));
        expect($text)->toContain('required for your account')->toContain($super->email);

        $this->actingAs($manager);
        $text = json_encode(array_map(fn ($component) => $component->getContent(), codeProvider()->getManagementSchemaComponents()));
        expect($text)->toContain('not required');

        expect(codeProvider()->getActions())->toBe([]);
    });
});
