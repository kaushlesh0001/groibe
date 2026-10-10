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

## Pass 2: Agreements, Briefs, Projects, SOW

None of these are built. Each needs a spec decision or a later pass.

1. **Client registration does not include the Client MSA step.** Spec 12 lists "accept Client MSA" in registration. Pass 2 added a separate owner-only page at `/app/client/agreements/client-msa`, linked from the dashboard and from submit errors, and the gate enforces it. Folding it into registration waits for the Pass 1 gap where a self-registered client has no organization (Pass 1 item 4).
2. **SOW acceptance needs a template id, but `sows` has no column for it.** The acceptance row in `agreement_acceptances` requires `template_id`, so the rendered template id and version are kept in `sows.body_variables` as `_template_id` and `_template_version`. A `sows.template_id` column would be cleaner. Needs a spec change.
3. **SOW and agreement HTML is shown to clients unsanitized.** Template text and SOW bodies are admin-authored, and the rich editor limits tags, but nothing sanitizes on save. A malicious or compromised admin could run script in a client's session. Add an HTML sanitizer on save, and a CSP, in Pass 9. SOW values substituted from client data are escaped today.
4. **Impersonation can accept legal agreements.** A super admin impersonating a client owner can tick the box and accept the MSA or a SOW as that client, and the record shows the client. Consider refusing acceptance actions while impersonating. Needs a decision.
5. **Draft projects are hidden from clients.** Spec 5 says clients read their organization's projects. Pass 2 hides `draft` projects (and draft SOWs) because the contract value and scope are still being set. Confirm this is wanted.
6. **Brief and SOW notifications only.** Project transitions after `sow_accepted` write activity log entries but send no notification. Spec 14 lists none for project status, so they arrive with the work engine in Pass 4.
7. **Contributors cannot read projects yet.** `ProjectPolicy` denies contributors until assignments exist (spec 5 "R if assigned"). Pass 4 adds that rule.
8. **`PendingPassFourGuards` blocks two transitions in production.** `staffing -> active` and `delivered -> accepted` refuse until Pass 4 binds real checks for assignments and milestones. Tests use a fake. Replace the binding in Pass 4 and add the real tests there.
9. **Project code concurrency is tested by forcing a collision, not by parallel requests.** SQLite cannot run parallel writers. The generator uses `lockForUpdate` and the unique index with a retry. Run a parallel create check on MySQL staging.
10. **Local PHP is 8.4, the host target is 8.3.** Herd runs PHP 8.4.26 here. Run the suite on 8.3 before deploy.
11. **Filament file list uses signed links valid for 10 minutes.** `config('groibe.uploads.signed_url_minutes')`. Admins reloading an old page see an expired link. Fine for now.
12. **Brief draft cleanup.** Abandoned drafts and their uploaded files stay forever. Add a scheduled cleanup in Pass 9 housekeeping.
13. **`organization_users` is read per check.** `User::organizationRole()` memoizes per request, but lists of policy checks across many organizations would still query once per organization. Not an issue for clients (one organization).
14. **PHPStan cache files are tracked in git.** `storage/framework/phpstan/` was committed in Pass 1, so every Larastan run dirties the tree. Untrack it and add `/storage/framework/phpstan` to `.gitignore`. Pass 2 commits leave those files out.
