<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Notifications\AdminSignInCode;
use Closure;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Facades\Filament;
use Filament\Forms\Components\OneTimeCodeInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\RateLimiter;
use SensitiveParameter;
use Throwable;

/**
 * The emailed sign-in code for the admin panel (spec 17.2, SCR-040, amending SCR-029). It is Filament's own email authentication
 * (6 digits, stored hashed in the cache, single use, a newer code replaces the older) with the rules the built-in one lacks:
 *
 *  - valid for `groibe.admin_email_code.expires_minutes` (10), not 4;
 *  - one send a minute, the first send at sign-in included, so a resend needs a minute's wait;
 *  - 5 wrong codes lock the person out for 15 minutes, count across sessions, and end the code that was open;
 *  - the email is sent at once and never queued (see `AdminSignInCode`), and a send that fails is reported without breaking the page;
 *  - it is not an opt-in: a super admin always has it, any other admin has it while the `two_factor_admin` flag is on
 *    (`User::mustUseEmailCode()`), so the profile page shows a note instead of set-up and disable buttons.
 *
 * The code itself is never written to the audit log, the email log or any error log. A lockout is written to the audit log
 * (who and for how long), nothing else.
 */
class AdminEmailCodeAuthentication extends EmailAuthentication
{
    /** Why the last send returned false: `locked`, `throttled` or `failed`. */
    protected ?string $lastRefusal = null;

    public function __construct()
    {
        $this->codeExpiryMinutes = (int) config('groibe.admin_email_code.expires_minutes');
        $this->codeNotification = AdminSignInCode::class;
    }

    public function sendCode(HasEmailAuthentication $user): bool
    {
        $this->lastRefusal = null;

        if (! $user instanceof Authenticatable || ! $user instanceof Model) {
            return false;
        }

        if ($this->isLockedOut($user)) {
            $this->lastRefusal = 'locked';

            return false;
        }

        $key = $this->resendKey($user);

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 1)) {
            $this->lastRefusal = 'throttled';

            return false;
        }

        RateLimiter::hit($key, (int) config('groibe.admin_email_code.resend_seconds'));

        try {
            return parent::sendCode($user);
        } catch (Throwable $e) {
            // The code was stored before the email went; if the email did not go, nobody has it, so drop it.
            $this->forgetCode($user);
            report($e);
            $this->lastRefusal = 'failed';

            return false;
        }
    }

    public function verifyCode(#[SensitiveParameter] string $code, ?HasEmailAuthentication $user = null): bool
    {
        $user ??= Filament::auth()->user();

        if (! $user instanceof HasEmailAuthentication || ! $user instanceof Authenticatable || ! $user instanceof Model) {
            return false;
        }

        if ($this->isLockedOut($user)) {
            return false;
        }

        if (parent::verifyCode($code, $user)) {
            $this->getCacheRepository()->forget($this->attemptsKey($user));

            return true;
        }

        $this->recordWrongCode($user);

        return false;
    }

    /**
     * @param  Authenticatable&HasEmailAuthentication  $user
     *
     * @phpstan-impure
     */
    public function isLockedOut(HasEmailAuthentication $user): bool
    {
        return $this->lockoutSecondsLeft($user) > 0;
    }

    /**
     * @param  Authenticatable&HasEmailAuthentication  $user
     *
     * @phpstan-impure
     */
    public function lockoutSecondsLeft(HasEmailAuthentication $user): int
    {
        $until = $this->getCacheRepository()->get($this->lockKey($user));

        return is_int($until) ? max(0, $until - now()->getTimestamp()) : 0;
    }

    /**
     * For the server command that resets a person's sign-in: clears the lockout, the wrong-code count and the resend wait.
     *
     * @param  Authenticatable&HasEmailAuthentication  $user
     */
    public function clearState(HasEmailAuthentication $user): void
    {
        $cache = $this->getCacheRepository();
        $cache->forget($this->attemptsKey($user));
        $cache->forget($this->lockKey($user));
        RateLimiter::clear($this->resendKey($user));
        RateLimiter::clear('filament-email-authentication:'.Filament::getUserScopedAuthIdentifier($user));
        RateLimiter::clear('filament-multi-factor-challenge:'.Filament::getUserScopedAuthIdentifier($user));
    }

    /**
     * @param  Authenticatable&HasEmailAuthentication  $user
     * @return array<int, mixed>
     */
    public function getChallengeFormComponents(Authenticatable $user): array
    {
        return [
            OneTimeCodeInput::make('code')
                ->label(__('filament-panels::auth/multi-factor/email/provider.login_form.code.label'))
                ->submitOnCompletion()
                ->validationAttribute('code')
                ->belowContent(Action::make('resend')
                    ->label(__('filament-panels::auth/multi-factor/email/provider.login_form.code.actions.resend.label'))
                    ->link()
                    ->action(function () use ($user): void {
                        if ($this->sendCode($user)) {
                            Notification::make()->title('We sent a new code. The old one no longer works.')->success()->send();

                            return;
                        }

                        Notification::make()->title($this->refusalMessage($user))->danger()->send();
                    }))
                ->required()
                ->rule(function () use ($user): Closure {
                    return function (string $attribute, #[SensitiveParameter] $value, Closure $fail) use ($user): void {
                        if ($this->isLockedOut($user)) {
                            $fail($this->lockoutMessage($user));

                            return;
                        }

                        if (is_string($value) && $this->verifyCode($value, $user)) {
                            return;
                        }

                        $fail($this->isLockedOut($user)
                            ? $this->lockoutMessage($user)
                            : __('filament-panels::auth/multi-factor/email/provider.login_form.code.messages.invalid'));
                    };
                }),
        ];
    }

    /** @return array<int, mixed> */
    public function getManagementSchemaComponents(): array
    {
        $user = Filament::auth()->user();

        return [
            Text::make(fn (): string => $user instanceof User && $user->mustUseEmailCode()
                ? "Email sign-in code: required for your account. A 6-digit code is sent to {$user->email} each time you sign in, and works for ".$this->getCodeExpiryMinutes().' minutes.'
                : 'Email sign-in code: not required for your account at the moment.')
                ->columnSpanFull(),
        ];
    }

    /** @return array<int, Action> */
    public function getActions(): array
    {
        return [];
    }

    /** @param  Authenticatable&HasEmailAuthentication&Model  $user */
    private function recordWrongCode(HasEmailAuthentication $user): void
    {
        $max = max(1, (int) config('groibe.admin_email_code.max_wrong'));
        $lockSeconds = max(1, (int) config('groibe.admin_email_code.lockout_minutes')) * 60;
        $cache = $this->getCacheRepository();
        $attemptsKey = $this->attemptsKey($user);
        $locked = false;

        $this->executeWithCacheLock($cache, "{$attemptsKey}.lock", function ($lock, $cache) use ($attemptsKey, $max, $lockSeconds, $user, &$locked): bool {
            $wrong = (int) $cache->get($attemptsKey, 0) + 1;

            if ($wrong < $max) {
                $cache->put($attemptsKey, $wrong, $lockSeconds);

                return true;
            }

            // The fifth wrong code: lock for the full period from now, and end the code that was open, so a fresh one is needed.
            $cache->put($this->lockKey($user), now()->getTimestamp() + $lockSeconds, $lockSeconds);
            $cache->forget($attemptsKey);
            $this->forgetCode($user);
            $locked = true;

            return true;
        });

        if ($locked && $user instanceof User) {
            activity('auth')->performedOn($user)->event('email_code_lockout')
                ->withProperties(['minutes' => (int) config('groibe.admin_email_code.lockout_minutes')])
                ->log('Admin sign-in locked after too many wrong email codes');
        }
    }

    /** @param  Authenticatable&HasEmailAuthentication&Model  $user */
    private function forgetCode(HasEmailAuthentication $user): void
    {
        $this->getCacheRepository()->forget($this->getCodeCacheKey($user));
    }

    /** @param  Authenticatable&HasEmailAuthentication  $user */
    private function refusalMessage(HasEmailAuthentication $user): string
    {
        return match ($this->lastRefusal) {
            'locked' => $this->lockoutMessage($user),
            'failed' => 'We could not send the email. Try again in a minute, or ask a super admin.',
            default => 'Please wait a minute before asking for another code.',
        };
    }

    /** @param  Authenticatable&HasEmailAuthentication  $user */
    private function lockoutMessage(HasEmailAuthentication $user): string
    {
        $minutes = max(1, (int) ceil($this->lockoutSecondsLeft($user) / 60));

        return "Too many wrong codes. Try again in {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').'.';
    }

    /** @param  Authenticatable&HasEmailAuthentication  $user */
    private function resendKey(HasEmailAuthentication $user): string
    {
        return 'groibe-admin-email-code-send:'.$this->userKey($user);
    }

    /** @param  Authenticatable&HasEmailAuthentication  $user */
    private function attemptsKey(HasEmailAuthentication $user): string
    {
        return 'groibe.admin_email_code.wrong.'.$this->userKey($user);
    }

    /** @param  Authenticatable&HasEmailAuthentication  $user */
    private function lockKey(HasEmailAuthentication $user): string
    {
        return 'groibe.admin_email_code.locked.'.$this->userKey($user);
    }

    /**
     * One key per person, not per session, so signing in again does not reset the count.
     *
     * @param  Authenticatable&HasEmailAuthentication  $user
     */
    private function userKey(HasEmailAuthentication $user): string
    {
        return hash('sha256', 'admin-email-code|'.Filament::getUserScopedAuthIdentifier($user));
    }
}
