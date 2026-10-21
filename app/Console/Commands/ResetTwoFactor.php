<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\AdminEmailCodeAuthentication;
use Illuminate\Console\Command;

/**
 * Recovers a locked-out admin from the server (SCR-029, SCR-040). For one person it removes the authenticator-app secret and the
 * recovery codes, and it always clears the emailed code's lockout, wrong-code count and resend wait, so the person can ask for a
 * fresh code at once. The emailed code itself is required by role and flag, so it is not something a reset can turn off. It needs
 * shell access to the host, which is the point: nobody can use it from the web, and the reset is written to the audit log with
 * the person's email.
 */
class ResetTwoFactor extends Command
{
    protected $signature = 'groibe:two-factor-reset {email : The email address of the person} {--force : Do not ask for confirmation}';

    protected $description = 'Remove one admin\'s authenticator app and recovery codes, and clear their email sign-in code lockout';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user has the email {$email}.");

            return self::FAILURE;
        }

        $hasApp = $user->hasTwoFactor() || $user->two_factor_recovery_codes !== null;

        if (! $hasApp) {
            AdminEmailCodeAuthentication::make()->clearState($user);

            $this->info("{$user->email} has no authenticator app or recovery codes. Nothing was changed. Their email sign-in code lockout and resend wait were cleared.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Turn off the authenticator app and recovery codes for {$user->email} ({$user->role->value})?")) {
            $this->info('Cancelled. Nothing was changed.');

            return self::SUCCESS;
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->saveQuietly();
        AdminEmailCodeAuthentication::make()->clearState($user);

        activity('auth')->performedOn($user)->event('two_factor_reset')
            ->withProperties(['email' => $user->email, 'by' => 'server command'])->log('Two-factor authentication reset from the server');

        $this->info("The authenticator app and recovery codes are off for {$user->email}, and their email sign-in code lockout is cleared. The emailed code is required by role and is not affected.");

        return self::SUCCESS;
    }
}
