# Suggestions

Ideas and gaps found while building. None of these are built. Each needs a spec decision or a later pass.

## Pass 1: Foundation

1. **Pest Laravel plugin is not installed.** `pestphp/pest-plugin-laravel` 4.x supports Laravel 12 at most and 5.x requires PHP 8.4. The hosting target is PHP 8.3, so only `pestphp/pest` 3.8 is installed. Tests use `Tests\TestCase` and Laravel's own helpers, so nothing is missing today. Revisit if the host moves to PHP 8.4. Same reason `spatie/laravel-activitylog` is on 4.x (5.x needs PHP 8.4).
2. **Invite links cannot be revoked individually.** An invite is a signed, expiring URL keyed on the user's ULID (spec 6.11, 15.1). Resending issues a new link but the earlier one stays valid until it expires or is redeemed. Revocation would need an `invite_token` or `invite_sent_at` column on `users`, which spec 7.1 does not list.
3. **Invited contributors stay `pending` after activating their password.** Spec 6.10 needs admin review and Member Agreement acceptance before `active`, which Pass 3 builds. Until then `ActivateInvitedUser` verifies the email and sets the password only.
4. **Client self-registration creates an account only.** Spec 6.11 (`org_pending`, `org_verified`) and the MSA gate belong to Pass 2. Clients can log in to an empty dashboard and have no organization until an admin links one.
5. **`profiles.onboarding_state` holds both contributor (spec 6.10) and client (spec 6.11) states in one enum.** Pass 1 stores `registered` only. Pass 3 should confirm whether client state belongs on `organizations.status` instead.
6. **FULLTEXT index on `profiles(headline, bio)` is MySQL only.** The migration skips it on SQLite, so the test database has no FULLTEXT index. Add a MySQL-backed CI job before Pass 3 relies on search.
7. **Two-factor auth for admins is not built.** The `two_factor_admin` flag exists. TOTP is Pass 9 item 4.
8. **Admin dashboard widgets.** The panel shows the default account widget only. Spec 13 widgets are Pass 9.
9. **Starter kit leftovers.** `resources/views/components/layouts/app/header.blade.php`, `layouts/auth/card.blade.php`, `layouts/auth/split.blade.php` and the starter `settings/delete-user-form` are unused or unreviewed. The delete-account form lets a user hard-delete themselves, which conflicts with soft deletes and DPDP admin-processed deletion (spec 17.2). Decide in Pass 9.
10. **Tests need built assets.** Pages that render `@vite` fail without `public/build`. Run `npm run build` before `php artisan test`, and in CI.
11. **`artisan test` appeared to hang on this machine.** Running `php vendor/bin/pest` directly completed normally. Worth checking `phpunit.xml` and the Herd PHP setup if it recurs.
