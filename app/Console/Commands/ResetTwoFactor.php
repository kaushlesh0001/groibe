<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Recovers a locked-out admin from the server (SCR-029): removes one person's two-factor secret and recovery codes, so the
 * next sign-in goes through set-up again. It needs shell access to the host, which is the point: nobody can use it from the
 * web, and the reset is written to the audit log with the person's email.
 */
class ResetTwoFactor extends Command
{
    protected $signature = 'groibe:two-factor-reset {email : The email address of the person} {--force : Do not ask for confirmation}';

    protected $description = 'Turn off two-factor authentication for one user, so they can set it up again';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("No user has the email {$email}.");

            return self::FAILURE;
        }

        if (! $user->hasTwoFactor() && $user->two_factor_recovery_codes === null) {
            $this->info("{$user->email} has no two-factor authentication set up. Nothing was changed.");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Turn off two-factor authentication for {$user->email} ({$user->role->value})?")) {
            $this->info('Cancelled. Nothing was changed.');

            return self::SUCCESS;
        }

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->saveQuietly();

        activity('auth')->performedOn($user)->event('two_factor_reset')
            ->withProperties(['email' => $user->email, 'by' => 'server command'])->log('Two-factor authentication reset from the server');

        $this->info("Two-factor authentication is off for {$user->email}. They set it up again at their next admin sign-in.");

        return self::SUCCESS;
    }
}
