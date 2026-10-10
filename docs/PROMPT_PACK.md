# GROIBE PROMPT PACK (for Claude Code, Sonnet 5.5)

## How to use this
- Repo layout: put `CLAUDE.md` in the repo root. Put this file and `GROIBE_MASTER_SPEC.md` in `docs/`.
- One pass per Claude Code session. Start every session with the **Session Opener**.
- Do not move to the next pass until the **Gate** at the end of the current pass is green.
- Commit and tag at the end of each pass (`pass-1-done`, etc.) so you can roll back.
- If Claude Code deviates from the spec, use the **Repair Prompt**.
- Keep sessions focused. If context gets long, start a new session with the Session Opener.

---

## Session Opener (paste at the start of every session)

```
Read CLAUDE.md, then read docs/GROIBE_MASTER_SPEC.md completely, start to end, before doing anything else.
Then read docs/PROMPT_PACK.md and find the pass I name below.
Confirm in 5 bullets: the pass goal, the spec sections it covers, the models/tables involved, the tests required, and anything ambiguous.
If anything is ambiguous in data model, permissions, or state machines, stop and ask. Otherwise proceed.
Pass to execute: <PASS NUMBER AND NAME>
```

## Repair Prompt (use when output drifts)

```
Stop. Compare what you built against docs/GROIBE_MASTER_SPEC.md sections <X, Y>.
List every mismatch (missing field, wrong state, wrong permission, extra feature).
Do not fix anything yet. Show the list, then fix only those items, add tests that would have caught each one, and rerun test, pint, and phpstan.
```

## Review Prompt (run at the end of each pass)

```
Audit this pass against the spec and the pass's Done-when list.
Check: authorization on every route and Filament resource, N+1 queries on every new page, missing indexes, missing tests for each state transition and each denied access path, secrets in code, and anything built that the spec did not ask for.
Report findings by severity. Fix critical and high ones. Log the rest in docs/SUGGESTIONS.md.
```

---

## PASS 0: Prerequisites (manual, you do this, about 1 day)

- [ ] Domain DNS, Cloudflare account, MilesWeb PHP set to 8.3, SSH, Git, Composer verified.
- [ ] MySQL database and user created on MilesWeb. SMTP mailbox created.
- [ ] Local: PHP 8.3, Composer, Node, MySQL (Laravel Herd works well).
- [ ] Private GitHub repo. Add `CLAUDE.md`, `docs/GROIBE_MASTER_SPEC.md`, `docs/PROMPT_PACK.md`.
- [ ] Paste real legal text into a separate file for later (Member Agreement, Client MSA, SOW template). Do not give Claude Code anything confidential you would not commit.
- [ ] Confirm the spec section 22 defaults are acceptable. Edit the spec now if not.

---

## PASS 1: Foundation

**Spec sections:** 4, 5, 7.1, 7.2, 17, 18, 19 (part), 16 (auth routes)

**Prompt:**
```
Execute PASS 1: Foundation.

1. Create a new Laravel 13 project in this repo (or use the existing skeleton if present). Use the Livewire-based starter for authentication, then adapt it to our routes and roles. Verify installed versions with `php artisan about` and read the docs of the installed Livewire and Filament majors before coding.
2. Install and configure: spatie/laravel-permission, spatie/laravel-activitylog, spatie/laravel-medialibrary, barryvdh/laravel-dompdf, Pest, Pint, Larastan (level 5), Filament admin panel at /admin.
3. Configure for shared hosting: queue=database, session=database, cache=file, mail via env, a scheduler entry that runs `queue:work --stop-when-empty --max-time=50` every minute, and `Model::preventLazyLoading()` outside production.
4. Create migrations, models, factories and enums for spec 7.1 (users, profiles, organizations, organization_users), 7.2 (categories, skills, skill_user, project_types), and the feature_flags and settings tables. Add all indexes listed in 7.10 that apply.
5. Implement roles and permissions exactly per spec sections 4 and 5 (seed the permission sets). Implement Policies for User, Profile, Organization.
6. Implement auth: login, logout, forgot/reset password, email verification, throttling, `/register/contributor`, `/register/client` (accounts only for now, no onboarding steps yet), and signed `/invite/{token}` activation for admin-created users.
7. Implement the FeatureFlags and Settings services with caching, and a Filament admin for Users, Organizations, Skills, Categories, Project Types, Feature Flags and Settings.
8. Impersonation (super_admin only) with a visible banner and activity logging. Admin audit log page (read-only).
9. Base layouts: public layout, app layout (contributor/client), Tailwind brand tokens from CLAUDE.md, self-hosted font.
10. Seeders: roles/permissions, super admin from env, categories, skills, project types, feature flags per spec 18.
11. Tests: auth flows, role middleware, policy tests for User/Organization (including cross-organization denial), impersonation logging, feature flag toggling.

Do not build anything beyond this scope. When done, run tests, pint, phpstan and report.
```

**Done when:**
- Admin can log in at `/admin`, manage users, organizations, skills, flags; impersonation works and is logged.
- Contributor and client can register, verify email, log in, and land on an empty dashboard for their role.
- All tests, Pint, Larastan pass.

**Gate:** you can create an organization and a client user by hand in Admin, send an invite, and activate it.

---

## PASS 2: Agreements, Briefs, Projects, SOW (core delivery spine)

**Spec sections:** 6.1, 6.2, 6.3, 7.3 (briefs, projects, sows), 8, 5

**Prompt:**
```
Execute PASS 2: Agreements, Briefs, Projects, SOW.

1. Migrations, models, enums, factories and policies for: agreement_templates, agreement_acceptances, briefs, brief_skills, projects, project_skills, sows. Brief and project files via media library private collections.
2. Agreement templates: versioned, Filament-managed (super_admin), seeded with placeholder bodies. Implement acceptance recording (user, org, version, ip, user agent, timestamp). Gate: client owner must accept the Client MSA before submitting a brief or accepting a SOW.
3. Brief flow per spec 6.1: Actions for submit, request info, resume, mark in review, decline, convert to project. Client UI under /app/client/briefs (guided multi-step Livewire form, list, detail with status and admin questions). Admin Filament BriefResource with the "Convert to Project" action (pre-fills project, copies skills, generates project code GRB-YYYY-NNNN safely).
4. Project state machine per spec 6.2 with one Action per transition, enforcing guards (for example staffing -> active requires an active assignment; assignments come in a later pass, so stub the guard behind an interface and test it with a fake until Pass 4).
5. SOW: SowRenderer service with variable substitution per spec 8, versioning and supersede logic, send action, client accept action (checkbox, records acceptance), print view, and PDF export via dompdf. Admin SowRelationManager on projects.
6. Client: /app/client/projects list and detail (read-only for now), SOW view and accept.
7. Notifications for: brief status changes, SOW sent, SOW accepted. Build the NotificationDispatcher service (database + mail, per-category preferences placeholder) and email templates.
8. Tests: every transition (valid and invalid), SOW versioning, acceptance recording, client cannot see other orgs' briefs/projects, client cannot submit a brief without MSA acceptance or verified org, project code uniqueness under concurrency.

Do not build milestones, tasks, staffing, or open roles yet.
```

**Done when:**
- A verified client submits a brief; admin converts it to a project, sends a SOW; client accepts it; everything is logged and notified.

**Gate:** run the full brief -> SOW accepted flow manually, then run the Review Prompt.

---

## PASS 3: Contributor onboarding and profiles

**Spec sections:** 6.10, 7.1, 7.2, 11 (registration, profile), 8

**Prompt:**
```
Execute PASS 3: Contributor onboarding and profiles.

1. Contributor onboarding per spec 6.10 and section 11: multi-step flow (account, profile, skills with level, availability, Member Agreement acceptance, submit for review). Persist progress between steps. Require verified email before pending_review.
2. Profile management (edit, photo upload with limits, public toggle, slug generation, skills with levels).
3. Admin ContributorResource: review queue, approve/reject/suspend actions (state machine Actions with notifications), skill verification action, notes.
4. Middleware `active.contributor` blocks non-active contributors from apply/assignment areas, showing a clear status page.
5. Public contributor directory and profile pages (only is_public + active), noindex otherwise, with FULLTEXT search on headline/bio and skill filters. Respect feature flag `public_directories`.
6. Contributor dashboard shell with onboarding status.
7. Tests: onboarding state transitions, agreement gating, non-active access denial, private profiles not exposed (including in sitemap and search), upload validation.

Do not build open roles, assignments, or work yet.
```

**Done when:** a contributor registers, completes profile, is approved by admin, and appears (if public) in the directory.

**Gate:** privacy test: a private contributor must never appear in any public route, search, or sitemap.

---

## PASS 4: Staffing and the Work Engine

**Spec sections:** 6.2, 6.6, 6.7, 6.8, 6.9, 7.3 (milestones, tasks, submissions, deliverables), 7.4 (assignments), 11 (My Work), 12 (Projects, Deliverables)

**Prompt:**
```
Execute PASS 4: Staffing and the Work Engine.

1. Migrations, models, enums, factories, policies for: assignments, milestones, tasks, task_comments, submissions, deliverables, deliverable_submission. Submission files via media library private collection with size/type limits.
2. Assignments: admin invites a contributor to a project (state machine per 6.6), contributor accepts/declines, admin removes. Replace the Pass 2 stub guard so staffing -> active requires at least one active assignment. Enforce rule: only `active` contributors can be assigned.
3. Milestones and tasks (admin Filament relation managers, plus a project workspace page for delivery managers): create, order, assign tasks to assignments, estimates, due dates.
4. Contributor My Work: assigned projects, task board (todo, in progress, submitted, approved), task detail with comments, submission upload with versioning, resubmission after changes_requested.
5. Admin QA Queue: submissions awaiting internal review, approve or request changes with notes. Only qa_approved submissions can attach to a deliverable.
6. Deliverables and milestone sharing: admin shares a milestone with the client (shared_at), client sees deliverables, accepts or requests changes with a note (per 6.9). Project moves to delivered/accepted by the rules in 6.2.
7. Client project detail: milestone progress, team shown by first name + role + headline (respect project-level hide setting), no internal comments, no rates, no unapproved submissions.
8. Private file serving through signed, authorized routes: contributor sees own, assigned admin sees all, client sees only files in shared deliverables of their org.
9. Notifications for every transition in this pass. Overdue task scheduled job (daily) notifying delivery manager.
10. Tests: all transitions valid and invalid, file access matrix (contributor A cannot read contributor B's submission, client cannot read unshared or internal files, other org denied), internal comment visibility, project state progression end to end.
```

**Done when:** you can run a project from `sow_accepted` through staffing, task work, QA, client acceptance and `accepted`.

**Gate:** run the full end-to-end Feature test for one project lifecycle; try to break file access manually with another user's session.

---

## PASS 5: Open Roles and Applications (controlled add-on)

**Spec sections:** 2.2, 6.4, 6.5, 7.4 (open_roles, applications), 11 (Discover, Apply), 10.2

**Prompt:**
```
Execute PASS 5: Open Roles and Applications.

1. Migrations, models, enums, factories, policies for open_roles, open_role_skills, applications.
2. Admin: OpenRoleResource (create from scratch or "Publish as Open Role" from a project; visibility invite_only/members_only/public; featured toggle), ApplicationResource with shortlist/offer/reject actions per 6.5. Accepting an offer creates an Assignment (invited -> active via the existing assignment flow).
3. Public: /opportunities list with filters (skill, type, work mode, compensation, duration), FULLTEXT search, and /opportunities/{slug} detail with SEO tags. Respect visibility and the `open_roles` flag.
4. Contributor: Discover (members_only and public roles), apply form per section 11 (motivation, experience, availability, expected amount, select proof-of-work), My Applications with status and withdraw. Rule-based recommended roles on the dashboard (skill overlap + availability).
5. Optional client view of shortlist only when project.client_can_view_shortlist is true (first name, headline, skills, no contact info).
6. Notifications for application events.
7. Tests: visibility rules, non-active contributors cannot apply, duplicate application blocked, offer accept creates assignment, flag off hides everything (routes 404, homepage section hidden), clients cannot create open roles.
```

**Done when:** admin publishes a role, a contributor applies, admin shortlists and offers, contributor accepts and appears as an active assignee.

**Gate:** toggle `open_roles` off and confirm nothing leaks publicly or in the sitemap.

---

## PASS 6: Reviews, Proof of Work, Reputation

**Spec sections:** 6.2 (closed), 7.5, 9, 11 (Portfolio, Reputation), 12 (Reviews, showcase consent)

**Prompt:**
```
Execute PASS 6: Reviews, Proof of Work, Reputation.

1. Migrations, models, enums, factories, policies for proof_of_work, proof_of_work_skill, reviews, reputation_facts, organization_stats.
2. Review flows per section 9: client reviews contributors after accepted; contributors review the client after closed (admin-only visibility unless `public_client_reviews`); admin can add internal reviews. Moderation queue in Admin with publish/hide.
3. Project close Action: moves accepted -> closed, creates draft ProofOfWork per assignment, requests reviews, prompts client for showcase_consent (none/anonymous/named), queues reputation recompute.
4. Contributor Portfolio: edit draft proof-of-work text and metrics, request publication. Admin approves; publication requires project consent; anonymous display rules enforced.
5. ReputationCalculator service and queued job: evidence counts and averages only (no composite score), cached in reputation_facts, recomputed on close and on review publish.
6. Public: /work and /work/{slug} (published only, respects flag and consent), and proof/reputation facts on public contributor profiles.
7. Tests: consent rules (none -> never public, anonymous -> no client name), moderation gating, reputation math with fixtures (first-pass approval %, on-time %), review uniqueness, contributors cannot see client-to-contributor reviews of others.
```

**Done when:** closing a project yields reviews, proof-of-work drafts, consent, and correct reputation facts; published items appear publicly only when allowed.

**Gate:** confirm a "none" consent project can never surface publicly via any route, search, or sitemap.

---

## PASS 7: Messaging, Notifications, Money tracking

**Spec sections:** 7.6, 7.8, 14, 11 (Payouts), 13 (Finance)

**Prompt:**
```
Execute PASS 7: Messaging, Notifications, Money tracking.

1. Threads, participants, messages (migrations, models, policies). Project threads with internal-only messages, support threads. Livewire thread UI with polling every 20 seconds, unread counts, message file attachments (private). Respect `messaging` flag.
2. Notification centre: bell, unread count, list, mark read, per-category preferences (security notifications cannot be disabled). Email log table and failure logging.
3. Payouts and invoice references per 7.6: Filament Finance resources (finance_ops and super_admin), status transitions, contributor read-only payouts page, CSV export. No client access to payouts.
4. Tests: participant isolation, internal messages hidden from clients and contributors, support thread privacy, finance permissions, preference handling.
```

**Done when:** a project thread works for all three parties with correct visibility, and finance records track payouts and invoice references.

---

## PASS 8: Public Website and Homepage CMS

**Spec sections:** 10, 18, 7.9

**Prompt:**
```
Execute PASS 8: Public Website and Homepage CMS.

1. Migrations, models, factories for cms_sections, featured_items, testimonials, faqs, pages, contact_messages.
2. Filament resources: Homepage Sections (ordering, toggling, JSON-backed content editors), Featured Items (pick open roles, contributors, organizations, proof of work, testimonials), Testimonials, FAQs, Pages, Contact Messages.
3. Public pages per spec 10.2 with the homepage built from cms_sections in the order and visibility set in Admin. Implement all 11 homepage sections. Stats use live DB counts with admin override values. Sections that depend on a disabled flag or empty data hide gracefully.
4. Pages: how-it-works, for-contributors, for-clients, about, contact (stored + emailed, honeypot, throttle), faq, terms, privacy from DB templates, CMS pages, sitemap.xml and robots.txt (exclude private/noindex), meta tags, OG tags, JSON-LD.
5. Visual polish to spec 10.3: brand tokens, typography, responsive, accessible. Public copy must contain no em dashes and no AI-related wording. Keep Lighthouse performance and accessibility scores high; no third-party requests.
6. Caching: cache public lists and stats for 5-15 minutes with bust-on-change observers.
7. Tests: homepage renders with empty DB and with seeded data, hidden sections do not render, private entities excluded from sitemap, contact form protections.
```

**Done when:** an admin can reorder, edit, hide, and feature everything on the homepage with no code change.

**Gate:** run Lighthouse on home and opportunity pages and record scores in `docs/SUGGESTIONS.md`.

---

## PASS 9: Admin dashboards, Import/Export, Hardening

**Spec sections:** 13, 15, 17, 20

**Prompt:**
```
Execute PASS 9: Admin dashboards, Import/Export, Hardening.

1. Admin dashboard widgets per spec 13: counts by status, pipeline funnel, QA queue, overdue tasks, applications awaiting action, reviews awaiting moderation, system status (queue backlog, failed jobs, last cron run).
2. Imports per spec 15.1 for contributors and clients: upload, column mapping, dry-run preview, queued chunked import, error report download, idempotency by email and by organization name+website/gstin, grouping multiple contacts under one organization, unknown skills created inactive and reported, bulk throttled invite sending with signed links.
3. Exports per spec 15.2 with permission checks and queued exports above 5,000 rows.
4. Security hardening per 17.2: security headers and CSP, rate limits on all sensitive endpoints, TOTP 2FA for admins (required for super_admin), upload validation audit, password rules, review every route for authorization.
5. Privacy: user data export and deletion request handling (admin processed), consent timestamps.
6. Ops per 17.4: log channel, exception emails (throttled), `groibe:backup-db` command writing outside the web root, scheduled housekeeping.
7. Full regression: a single end-to-end Feature test per persona (guest, contributor, client, admin) and the cross-organization data leak test suite.
8. Run the Review Prompt across the entire codebase.
```

**Done when:** import of real sample CSVs works with a dry run and an error report; all acceptance criteria in spec section 20 pass.

---

## PASS 10: Deployment (docs/DEPLOY.md, then do it)

**Prompt:**
```
Execute PASS 10: Deployment.

Write docs/DEPLOY.md for a shared cPanel host (MilesWeb) covering: PHP 8.3 selection, document root strategy (app outside public_html; point the domain root at public or copy public into public_html with corrected paths), Composer install with `--no-dev --optimize-autoloader`, uploading built Vite assets, `.env` production template (debug off, DB, SMTP, ADMIN_EMAIL), `key:generate`, `migrate --force`, config/route/view caching, the cron entries (`* * * * * php /path/artisan schedule:run`), file permissions, the private storage strategy and a test that private files are not publicly reachable, a rollback procedure, a DB backup and restore drill, SPF/DKIM/DMARC setup notes, Cloudflare settings, and a post-deploy smoke test checklist.

Also create a deploy script (`scripts/deploy.sh`) usable over SSH that pulls from Git, installs dependencies, migrates, caches, and clears opcache safely. Do not include secrets.
```

**Your manual checklist after Claude Code finishes:**
- [ ] Deploy to a staging subdomain first. Run the smoke test.
- [ ] Verify cron is running (system status widget).
- [ ] Send test emails, check spam placement, fix SPF/DKIM/DMARC.
- [ ] Restore a backup into a scratch DB to prove it works.
- [ ] Paste real legal templates into Admin and publish.
- [ ] Import contributors and clients on staging, review the error report, then import to production.
- [ ] Switch to production, then send invites in small batches.
- [ ] Seed first real open roles and approve first proof-of-work items before opening public signup.

---

## Tips that save rework

- **Always start with the Session Opener.** Claude Code forgetting the spec is the main cause of broken linking.
- **Keep passes small.** If a pass feels too big, split by numbered item and run them as sub-sessions, still opening each with the Session Opener.
- **Demand tests as proof.** "Done" without a green test run is not done.
- **Use `git diff` between passes.** Read what changed in migrations and policies before moving on, since those are the expensive things to fix later.
- **Spec changes go through the change log (spec section 23).** If you change the data model mid-build, update the spec first, then tell Claude Code to reconcile.
- **Run on staging with the demo dataset** before touching real data.
- **If a package's API seems off,** tell Claude Code to read the installed version's docs and `vendor/` source rather than relying on memory.
