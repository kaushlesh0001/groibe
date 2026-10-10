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

1. **Resolved (SCR-010).** The Client MSA is accepted by the owner on its own page, not in registration. Registration stays accounts only until the Pass 1 gap where a self-registered client has no organization (item 4) is closed.
2. **Cleanup item (founder confirmed, keep for now): SOW template id lives in `sows.body_variables`.** The acceptance row in `agreement_acceptances` requires `template_id`, so the rendered template id and version are stored as `_template_id` and `_template_version`. Spec 7.3 allows json for flexible metadata, so this is within the spec. A real `sows.template_id` column would be cleaner and queryable. Do it with a spec change when convenient.
3. **SOW and agreement HTML is shown to clients unsanitized.** Template text and SOW bodies are admin-authored, and the rich editor limits tags, but nothing sanitizes on save. A malicious or compromised admin could run script in a client's session. Add an HTML sanitizer on save, and a CSP, in Pass 9. SOW values substituted from client data are escaped today.
4. **Impersonation can accept legal agreements.** A super admin impersonating a client owner can tick the box and accept the MSA or a SOW as that client, and the record shows the client. Consider refusing acceptance actions while impersonating. Needs a decision.
5. **Resolved (SCR-009).** Clients do not see draft projects or draft SOWs until the SOW is sent.
6. **Brief and SOW notifications only.** Project transitions after `sow_accepted` write activity log entries but send no notification. Spec 14 lists none for project status, so they arrive with the work engine in Pass 4.
7. **Contributors cannot read projects yet.** `ProjectPolicy` denies contributors until assignments exist (spec 5 "R if assigned"). Pass 4 adds that rule.
8. **Resolved in Pass 4 step 2.** `PendingPassFourGuards` is replaced by `EloquentProjectTransitionGuards` (an active assignment for `staffing -> active`, and at least one milestone with every milestone client-accepted for `delivered -> accepted`).
9. **Project code concurrency is tested by forcing a collision, not by parallel requests.** SQLite cannot run parallel writers. The generator uses `lockForUpdate` and the unique index with a retry. Run a parallel create check on MySQL staging.
10. **Local PHP is 8.4, the host target is 8.3.** Herd runs PHP 8.4.26 here. Run the suite on 8.3 before deploy.
11. **Filament file list uses signed links valid for 10 minutes.** `config('groibe.uploads.signed_url_minutes')`. Admins reloading an old page see an expired link. Fine for now.
12. **Brief draft cleanup.** Abandoned drafts and their uploaded files stay forever. Add a scheduled cleanup in Pass 9 housekeeping.
13. **`organization_users` is read per check.** `User::organizationRole()` memoizes per request, but lists of policy checks across many organizations would still query once per organization. Not an issue for clients (one organization).
14. **PHPStan cache files are tracked in git.** `storage/framework/phpstan/` was committed in Pass 1, so every Larastan run dirties the tree. Untrack it and add `/storage/framework/phpstan` to `.gitignore`. Pass 2 commits leave those files out.

## Pass 2 review (audit against spec and Done-when)

No critical or high findings. Medium and low items, none fixed:

15. **Medium: `briefs.submitted_at` has no index.** The admin queue sorts and filters on it. Spec 7.10 does not require it. Add when the table grows.
16. **Medium: an admin with `briefs.view` can open a client's draft brief by direct URL.** The list hides drafts, and the spec gives admins RUA on briefs, so access is allowed, but drafts are the client's unfinished work. Decide whether drafts should be admin-hidden entirely.
17. **Low: activity log writes two entries per transition.** The model's own change log and the Action's explicit entry both fire. Harmless, noisy. Drop `status` from the models' logged attributes or the explicit entry.
18. **Low: Livewire's temporary upload endpoint accepts any file type up to 20 MB.** The brief form validates type and size before the file is attached, and unattached temp files are purged, so nothing unsafe is stored permanently. Tighten `livewire.temporary_file_upload.rules` to the same extension allowlist.
19. **Low: no rate limit on MSA and SOW acceptance or brief submit.** Actions are idempotent or state-guarded. Consider a throttle in Pass 9 hardening.
20. **Low: `OrganizationPolicy` duplicates the org-role lookup now in `ChecksOrganizationAccess`.** Refactor it onto the trait. Left alone to avoid touching tested Pass 1 code.

## Pass 2 re-audit

No critical or high findings. New evidence added: `tests/Feature/QueryCountTest.php` proves every new list page (client briefs, projects, dashboard; admin briefs, projects, SOW relation manager) issues the same number of queries at 3 rows and at 10, and that finance cannot change SOWs.

21. **Medium, must be done at deploy (Pass 10): recorded IPs will be wrong behind Cloudflare.** `bootstrap/app.php` configures no trusted proxies, so `request()->ip()` returns the proxy address. MSA and SOW acceptances store that IP (spec 8). Configure trusted proxies for the Cloudflare ranges only. Trusting every proxy would let a client forge the stored IP.
22. **Medium: commercial fields stay editable after a SOW is accepted.** An admin can change `contract_value` and dates on the project edit form after `sow_accepted`, so the project can drift from the accepted SOW. Lock those fields once the SOW is accepted, or require a new SOW version.
23. **Medium (likely, not reproduced): client text in notification emails is rendered as Markdown.** Brief titles and the admin question go into markdown mail templates. HTML is escaped, but Markdown link syntax in a title may become a clickable link in an email to staff or the client. Escape Markdown characters in those values.
24. **Low: acceptance rows have no uniqueness.** A double submit could store two identical MSA acceptances by the same owner. `AcceptClientMsa` is idempotent in code, but a unique index on user, template and organization would make it airtight.

## Pass 3: Contributor onboarding and profiles

None of these are built. Each needs a spec decision or a later pass.

25. **Resolved (SCR-011).** `suspended -> active` (reinstate) exists, `rejected` is terminal in v1.
26. **Medium: two fields say "active".** `users.status` and `profiles.onboarding_state` can disagree, because the Users resource lets an admin edit `users.status` by hand. Pass 3 makes `onboarding_state` the source of truth and the public scope checks both, with tests for the inconsistent cases. A cleaner fix is to make `users.status` read-only for contributors in the Users resource, or derive it.
27. **Low: suspended contributors get no email.** `NotificationDispatcher::send` skips suspended users (Pass 2), and the suspend Action sets the status before notifying. The suspension is shown on their status page only. Decide whether security-style notices should bypass that skip.
28. **Low: the `verified` middleware hides the onboarding "verify your email" step.** An unverified contributor is redirected to the verification page before they can open onboarding, so the email check on step 1 and the Action guard are a second line of defence. Fine for now.
29. **Low: no admin view of the profile photo.** Photos live on the private disk and are served only to the owner and to the public for visible profiles. The admin review page shows no photo. Add a signed admin route if reviewers want it.
30. **Low: no `slug_redirects` table.** Spec 16 mentions it, spec 7 does not list it. Slugs never change after the first publish, so nothing needs redirecting yet. Build it with the first feature that can rename a slug.
31. **Low: rejection is final and silent on reasons.** The reason is kept in the activity log and not sent to the contributor (a spec 22 style default). Decide whether contributors should see a reason, and whether they may ever reapply.
32. **Low: no JSON-LD or Open Graph image on contributor pages.** Title, description, canonical and basic Open Graph tags are set. Add `Person` and `BreadcrumbList` JSON-LD with the SEO pass.
33. **Low: `/sitemap.xml` is built per request.** That keeps privacy changes instant, and it is fine at v1 volume. The spec schedules a sitemap refresh job. If it becomes a cache, it must be busted on every visibility, state and status change.
34. **Low: FULLTEXT search is untested on MySQL.** The suite runs on SQLite, where search falls back to `LIKE`. Natural-language FULLTEXT also ignores words shorter than `ft_min_word_len` and stop words. Run the directory search on MySQL staging.
35. **Low: public photo responses are `private, max-age=60`.** Short and private so a profile made private is not served from a shared cache for long. Cloudflare will not cache them. Revisit if photo load matters.
36. **Low: bulk contributor actions skip silently per record.** The notification reports the counts, not which records were skipped.
37. **Low: the starter-kit settings page still has name and email edit.** A contributor can change their display name there, and it flows to their public profile at once, with no review. Decide whether public name changes need approval.

## Pass 3 review (audit against spec and Done-when)

No critical or high findings. Checked: route and Filament authorization, N+1 on every new page (query-count tests for the directory and the admin list, lazy loading is on in tests), indexes, secrets and hard-coded values, public copy rules, mass assignment, and scope creep. Medium and low items, none fixed except the tests noted:

38. **Medium: no admin edit for contributors.** Spec 5 gives `delivery_manager` RU and `community_manager` CRUA on contributors, and spec 13 says admins create and edit any record in Filament. `ContributorResource` has a list and a review page only, so no admin can correct a profile, and `contributors.update` has no screen. Needs a decision: an edit page, or accept review-only for v1.
39. **Medium: a pending contributor has no way to accept a newer Member Agreement.** If a new version is published while someone is in `pending_review`, approval fails the agreement gate (correctly), but the onboarding page is read-only and offers no acceptance step. The admin has to contact the contributor. Needs a decision: show the acceptance on the pending page, or re-check only at submit.
40. **Medium: a soft-deleted contributor disappears from the admin list.** The resource scopes through the user, which excludes soft-deleted users, so an admin cannot see or restore them. This is safe for privacy (they are also hidden from the public). Add a trashed filter when deletion requests are processed (spec 17.2).
41. **Low: the Member Agreement body is rendered unsanitized on the onboarding page.** Same exposure as item 3 in the Pass 2 list, now also reaching contributors. Fix with the HTML sanitizer in Pass 9.
42. **Low: profile links are validated as https only in the Livewire form.** A future import or admin edit could store another scheme, and the public page renders the value as an `href`. Render a link only when it starts with `https://`, or validate in the model layer, when imports arrive (Pass 9).
43. **Low: concurrent first-publish could race on the slug.** The unique index prevents a duplicate, and a clash would surface as an error for one request. A six character random suffix makes it very unlikely. Retry on the unique violation if it ever shows up.
44. **Low: no throttle on directory search.** Each search runs a FULLTEXT query. The `public_directories` flag is the only brake. Add a throttle with the Pass 9 hardening.
45. **Low: a duplicate Member Agreement acceptance is possible under a double submit.** `AcceptMemberAgreement` checks first, but there is no unique index (same gap as item 24). The gate only needs one row, so the effect is a duplicate row.
46. **Low: `EnsureActiveContributor` returns 403 to guests when used without `auth`.** Every current use sits behind `auth`, so nothing is exposed. Keep `auth` ahead of it.


## Pass 4, step 2 (assignments)

47. **Low: staffing permissions reuse `projects.update`.** Spec 5 has no Assignment row, so invite and remove are gated by `projects.update` (delivery_manager and super_admin), and viewing by `projects.view`. If community managers should invite from open roles in Pass 5, add an `assignments` permission then.
48. **Low: invite and removal notifications link contributors to the dashboard.** My Work does not exist until step 4. Point `AssignmentInvited` and `AssignmentRemoved` at the My Work assignment page then.
49. **Resolved by SCR-015.** A milestone in `in_qa` returns to `in_progress` automatically when a task leaves `submitted` or `approved`, whether QA requests changes or an assignment removal returns the task to `todo`.

## Pass 4, step 3 (milestones and tasks)

Decisions taken where the spec is silent. Each is a small guard, easy to relax:

50. **Plans are frozen on `closed` and `cancelled` projects.** Milestone and task create, edit, delete, reorder and assign are refused there.
51. **A milestone is deleted only while `planned` and empty.** Anything else is client-visible or holds work.
52. **Tasks are added only to `planned` or `in_progress` milestones,** so a milestone already in QA or shared does not silently gain work.
53. **Tasks are assigned, reassigned or unassigned only while `todo`, and only to an `active` seat of the same project.** In-flight work moves by removing the contributor (spec 6.2), which returns their tasks to `todo`. This also keeps submissions tied to the assignment that made them.
54. **Approved tasks cannot be edited, reassigned or deleted.**
55. **Tasks have no sort column,** so only milestones are ordered (by `sort`, reorder Action). Tasks list by id. Add a `sort` to tasks if admins need to order them.
56. **Task change notifications go out only when title, description, estimate or due date change on an assigned task.** The previous assignee is also told when a task is reassigned (spec 14 "task assigned/changed").
57. **Low: the workspace page is planning only.** It shows milestones, tasks, progress, quick assign and reorder. Editing happens in the Milestones and Tasks tabs. Task and milestone creation notifications link contributors to the dashboard until My Work exists (see item 48).
58. **Low: `SyncMilestoneStatus` has no caller for task start or submit yet.** Task status changes arrive in step 4 (contributor) and step 5 (QA), which will call it. Today only `DeleteTask` calls it.

## Pass 4, step 4 (contributor My Work)

59. **Decision: work happens only while the project is `active` or `in_qa`.** Start, resume and submit are refused on `staffing`, `on_hold`, `delivered`, `closed` and `cancelled`. `in_qa` stays open so a task sent back for changes can still be reworked.
60. **Decision: a submission needs a note or at least one file, up to 10 files of 25 MB** (spec 17.2 submission cap). Allowed types are the existing document allowlist, so archives such as `.zip` are not accepted. Add them to `groibe.uploads.document_extensions` if contributors need to send bundles.
61. **Decision: resuming after changes is its own step** ("Start revising", `changes_requested -> in_progress`), then a normal submit. This follows spec 6.7 literally.
62. **Decision: assignment invites are answered from My Work,** with the project title and role shown. The invite is the only place a contributor sees a project before accepting, and rates are never shown.
63. **Decision: a contributor who is not on a project gets 404, not 403,** for its board and tasks, so project and task ids cannot be probed.
64. **Medium: submitted files have no download link yet.** The task page lists file names only. Step 8 adds the signed, authorized file routes and the `MediaPolicy` rule for submission files, then the links can be added.
65. **Low: task pages use numeric ids in the URL** (tasks have no `uuid`, spec 7.3 gives one to public-facing models only). Access is policy-checked and a non-owner gets 404.
66. **Low: the board paginates at 60 tasks,** and columns fill from the current page. Fine for realistic task counts per person per project.
67. **Low: contributor comments do not notify anyone.** Spec 14 lists messages, not task comments. Add a notification to the delivery manager if comments need to be seen without opening the task.

## Pass 4, step 5 (admin QA queue)

68. **Decision: any admin holding `submissions.approve` can review** (`delivery_manager`, `super_admin`), not only the project's own delivery manager. This matches SCR-013 for files and spec 5 for roles. The submission-received notification still goes to the project's delivery manager first.
69. **Decision: reviews are allowed only while the project is `active` or `in_qa`,** the same window as contributor work (item 59). A project on hold, delivered or closed refuses them.
70. **Decision: the approval note is optional, the change-request note is required.** Both are stored in `submissions.qa_note` and shown to the contributor. The note is internal and must stay out of every client view (step 7).
71. **Medium: reviewers cannot open submitted files yet.** The QA modal lists file names only, so a real review needs the signed file route and `MediaPolicy` rule that step 8 adds (SCR-013 gives `submissions.view` admins access). Do step 8 before relying on the queue.
72. **Low: the review modal is plain escaped text.** A richer inline preview can wait for step 8.

## Pass 4, step 6 (deliverables, sharing and client acceptance)

73. **Decision, confirmed by the founder: reopening after client changes (spec 6.9) reopens the milestone only.** `client_changes_requested -> in_progress` is an admin action. Follow-up work is added as new tasks; approved tasks stay approved (`approved` stays terminal in 6.7). The spec wording "tasks are reopened by Admin" is read as "follow-up tasks are opened". The client note stays on the milestone for the team. If every task is already approved, the reopened milestone goes straight back to `in_qa` so the admin can adjust the deliverable and share again.
74. **Decision: tasks can be added until the milestone is shared,** including while it is in QA, where the new task pulls it back to `in_progress` (SCR-015). This replaces item 52 for `in_qa`.
75. **Decision: sharing needs every task approved and a deliverable holding approved work.** Spec 6.9 does not say; this follows spec 2.1 ("only approved work is shared with the client"). Sharing also needs the project to be `active` or `in_qa`, and starts a fresh round: the earlier client note and answer are cleared (history stays in the activity log).
76. **Decision: a deliverable needs at least one approved submission of its own milestone,** and can be edited until the milestone is shared. The permission matrix gives delivery managers no delete on deliverables, so there is no delete action. To remove one, empty it by editing, or leave it unshared.
77. **Decision: `in_qa -> delivered` now enforces spec 6.2** ("allowed once every milestone is shared or later", and at least one milestone). If every milestone is already client-accepted at that moment, the project goes straight on to `accepted`.
78. **Decision: only client owners and managers see the Accept and Request changes buttons** (spec 12). Viewers read the page. The page lists contributor-free information only: deliverable title, summary and file names. Contributor names, submission notes, reviewer notes and rates are never rendered.
79. **Medium: clients cannot download deliverable files yet.** The page lists file names only. Step 8 adds the signed route and the `MediaPolicy` rule (client sees only files of shared deliverables of their organization).
80. **Low: the client project page does not show milestone progress yet** (step 7). The client dashboard "awaiting your action" panel (spec 12) is Pass 9 with the other dashboards.
81. **Low: the admin Deliverables list is read-only and has no actions.** Deliverables are built on the project's Deliverables tab, where the milestone and its approved work are in view.

## Pass 4, step 7 (client project detail)

82. **Decision: the progress line counts every milestone, but only shared and later milestones are listed by title.** "2 of 5 milestones accepted" reveals a count, not the work in flight. Planned, in-progress and in-QA titles stay internal (spec 6.9: `shared` means visible to the client). The SOW already lists planned milestones, so the count adds nothing sensitive. Say if the client should see all milestone titles with a status instead.
83. **Decision: the team is the active and completed assignments only,** shown as first name, role and headline once an SOW is accepted and unless `hide_team` is on (SCR-012). Invited, declined and removed contributors never appear. The rules live in `ClientTeam`, so other client views can reuse them.
84. **Decision: the team section is hidden entirely when `hide_team` is on,** with no hint that a team exists. Before the SOW is accepted, the client sees a short note that the team is introduced after acceptance.
85. **Low: `projects.hide_team` is now editable** from the admin project form (it had no control since SCR-012).
86. **Low: the project page shows no tasks, comments or submissions of any kind.** Clients see finished, approved work only through the Deliverables page. Per-task progress would need a decision on what the client may see of unshared work.

## Pass 4, step 8 (private file serving)

87. **Resolved items:** #64 (contributor links), #71 (QA reviewers can open files) and #79 (clients can open shared deliverable files) are done.
88. **Decision: a client can read a submission file only when** the submission is `qa_approved`, belongs to a deliverable that has `shared_at` set, whose milestone is shared or later, on a non-draft project of an organization the client belongs to. Viewers can read (spec 12 makes them read-only, not blind). Work that is approved but left out of the deliverable, earlier versions with changes requested, and anything in a milestone still in QA stay closed.
89. **Decision: a contributor reads only the files of their own submissions,** even after their seat ends. Teammates cannot read each other's files. Admin access follows SCR-013 (`submissions.view`).
90. **Low: signed links live 10 minutes** (`groibe.uploads.signed_url_minutes`), issued each time a page renders. A page left open longer shows a link that fails; reloading fixes it. Brief file links use the same lifetime.
91. **Low: three older call sites still build signed links by hand** (the brief forms). `SignedFileUrl` is the one place to use going forward, and they can switch to it when those pages are next touched.

## Pass 4, step 9 (notifications and the overdue job)

92. **Decision: "a notification for every transition" is read as spec 14 events plus the moments where someone must act.** Told: assignment invite, answer and removal; task assigned and changed; submission received and QA result; milestone reaching QA (delivery manager, new); milestone shared and the client's answer; project delivered (client managers, new); project accepted (delivery manager and contributors whose seats completed, new); overdue tasks (daily digest). `WorkNotificationsTest` lists each one and who is, and is not, told.
93. **Decision: these stay quiet by design.** A contributor starting or resuming a task, a comment, an admin reopening a milestone, automatic milestone moves to `in_progress`, and a delivered project returning to QA (the delivery manager already hears of the client's change request). Easy to change; each is one dispatcher call.
94. **Decision: the overdue job checks active and in-QA projects only,** counts a task as overdue from the day after its due date, includes tasks waiting for QA (the delay is then ours), and sends one digest per project to its delivery manager, listing the 15 most overdue with the total. With no delivery manager it goes to every admin who can edit tasks. Runs daily at 08:00 server time (the app timezone is UTC, so 13:30 IST); change the time in `routes/console.php` or set `APP_TIMEZONE`.
95. **Low: the job repeats every day until a task is approved or its due date moves.** That is the intent, but a snooze or weekly option may be wanted if digests become noise.
96. **Low: notification preferences are still the Pass 7 placeholder.** Everything above goes out as database plus mail. The new categories `task`, `submission` and `deliverable` are registered in `config/groibe.php` so preferences can switch them later.

## Pass 4, step 10 (end-to-end and transition tests)

97. **Found and fixed: three project Actions accepted a second source state.** `StartStaffing` and `ActivateProject` also worked from `on_hold`, bypassing Resume and its recorded state. `MoveProjectToQa` also worked from `delivered` after SCR-016, turning the automatic return into a manual one, which the founder did not choose. `ResumeProject` would also resume a project that was not on hold if a stale `status_before_hold` was left. Each Action now requires exactly its own source state (`mustBeIn`). The first two were Pass 2 code.
98. **Test coverage added:** `TransitionMatrixTest` tries every Pass 4 state-changing Action from every state of its subject and requires success from exactly the spec states. `ProjectLifecycleEndToEndTest` runs a project from `sow_accepted` to `closed` through the real admin, contributor and client pages, and a second run through a client change request after delivery.

## Pass 4 audit (against the spec and the Done-when list)

Done-when: a project runs from `sow_accepted` through staffing, task work, QA, client acceptance and `accepted`. Met, and proven by `ProjectLifecycleEndToEndTest` through the real admin, contributor and client pages. The ten prompt pack items are all built.

### Critical
None found.

### High (fixed in the audit)
99. **Livewire update requests skipped the role and active-contributor middleware.** Livewire re-applies only a short list of the page's middleware on its own update endpoint, and `role:*` and `active.contributor` were not on it. A contributor suspended while a page was open kept getting data on every refresh, and a client page kept refreshing after the account's role changed. Fixed: both middleware are registered as persistent, and `EnsureActiveContributor` now aborts by exception, which Livewire honours. Proven over real HTTP in `LivewireUpdateMiddlewareTest`. This also covers the Pass 3 pages.
100. **A refresh did not re-check access to the record.** A contributor taken off a task kept seeing it, and a client removed from an organization kept seeing its project, until the page was reloaded. Fixed: the task page, the project board and the client project page re-check on every render (404 or 403), and their model properties are `#[Locked]`.
101. **The 25 MB submission cap could not be reached.** Livewire's temporary upload rule capped every file at 20 MB, so a 24 MB submission was refused before the submission rules ran. Fixed: the shared cap is 25 MB, and the brief form enforces its own 20 MB cap on selection. Proven by a failing test first.

### Medium
102. **Fixed: `TaskComment` had no policy** (CLAUDE.md: a policy for every model). Added `TaskCommentPolicy`: delivery managers read all, a contributor reads the public comments on tasks they hold, clients none, nobody edits or deletes.
103. **Open, operational: the host must allow big uploads.** The local PHP here has `upload_max_filesize` 2M and `post_max_size` 8M, so even 25 MB submissions fail before Laravel sees them. Production cPanel needs both raised to at least 26M (and the Livewire temporary upload time is 5 minutes). `docs/DEPLOY.md` does not exist yet; it must also list the cron entry for `schedule:run`, which now runs the daily overdue digest. I did not change any PHP setting.
104. **Open, unverified: Filament pages authorize on load.** `ProjectWorkspace` checks permission in `mount`. If an admin's permission is withdrawn while the page is open, later refreshes may still answer. Confirm how Filament re-evaluates `canAccess` on update requests, and re-check in `getViewData` if needed.
105. **Open: the admin QA review modal and file links are plain HTML built in PHP.** Everything is escaped, but a Blade view would be easier to audit. Low risk, medium effort.

### Low
106. **`ProjectPolicy` does not give assigned contributors "R if assigned (limited)" from spec 5,** and its docblock still says Pass 4 will add it. Contributors reach a project only through the task board, which shows the title. Update the docblock, or add a limited `view` for active seats when a contributor project page is built.
107. **Starter-kit leftovers in `resources/views/components/layouts/app/header.blade.php`** link to the Laravel starter kit repo and docs. Pre-existing (Pass 0), in a layout the app does not use for the dashboards. Remove the file or those links before launch.
108. **No index for the overdue job's date filter.** `tasks` is indexed on `(project_id, status)`, and the job filters a few rows per project, so it is fine at v1 scale. Add `(project_id, due_date)` if projects grow into thousands of tasks. `whereDate` also stops index use; compare on the raw date column if it matters.
109. **The overdue command runs two queries per project** (count and list), chunked by 50. Acceptable for a daily batch; revisit with very many active projects.
110. **Some Actions have no authorization of their own, by design:** `SyncMilestoneStatus`, `CompleteProjectAssignments`, `ReopenProjectForChanges` and `AcceptProject(automatic: true)`. They run only inside an Action that has already authorized. Never call them from a controller, Livewire method or Filament action directly.
111. **Markdown mail:** text typed by clients and contributors (client notes, task titles) goes into markdown mail templates. HTML is escaped, but markdown syntax is not neutralized, so a note could render a link in an admin's email. Strip markdown from user text if mails ever go to outside parties.
112. **`User::organizationRole` is memoized per user object.** Correct per request on shared hosting. A long-lived process (queue worker, Octane) would hold a stale role. Clear the memo if the app ever moves to long-lived workers.
113. **No rate limit on Livewire actions** such as comments and submissions beyond the 60 per minute default on temporary uploads. Spec 17.2 lists rate limits only for login, register, contact, reset and invites.
114. **Hard-coded schedule time:** the overdue digest is set to 08:00 in `routes/console.php`. Move it to config if admins need to change it.
115. **The demo dataset does not exist yet** (spec 19 `DemoSeeder`, CLAUDE.md fresh demo DB command), so Pass 4 data (assignments, milestones, tasks, submissions) is not in any demo.

### Built beyond what the spec lists
All of it traces to a pass item or an accepted decision, and none adds a screen or a route the spec does not name:
- Three notifications not named in spec 14: a milestone reaching QA, a project delivered, a project accepted. Pass item 9 asks for a notification on every transition. Each is one dispatcher call and easy to remove.
- The overdue digest falls back to every admin who can edit tasks when a project has no delivery manager. The pass item says "delivery manager" only.
- Delivery goes straight on to `accepted` when every milestone is already client-accepted (follows 6.2 literally).
- Guards chosen where the spec is silent are logged as decisions in items 50 to 56, 59 to 63, 68 to 70, 73 to 77, 82 to 84, 88 to 89 and 92 to 94, each a small rule that is easy to relax.
- Spec changes SCR-015 and SCR-016 were founder-approved before they were edited into the spec.

### Coverage
- Every state-changing Action is exercised from every state of its subject (`TransitionMatrixTest`), and the enum edges are asserted exactly (`WorkDataLayerTest`, `ProjectTransitionsTest`).
- Denied paths: route protection is asserted for every `app/*` route, the work routes, the file route and the three new admin pages over HTTP; policy gaps (`DeliverablePolicy`, `TaskCommentPolicy`) and suspended or changed sessions are tested.
- Not covered, by choice: concurrent requests racing the row locks (double share, double accept), browser-level tests, and the Filament mid-session permission case (item 104).

## Brand change (SCR-017)

116. **Applied:** tokens (`brand-navy`, `brand-green`, `brand-green-alt`, `brand-blue`, `brand-teal`), the real logo files in the public header and footer (white on navy), app sidebar and header, sign-in pages, the admin panel (logo, dark-mode logo, favicon, colors), emails (PNG logo, green buttons with navy text) and the SOW print view and PDF. The home page has the navy hero "Where Ideas Find People" with one green word. `favicon.ico` was an empty placeholder and is now the supplied icon. Both icon links and a navy theme color are in every page head.
117. **Contrast, measured:** navy on white 13.7, white on navy 13.7, navy on green 4.65 (green buttons carry navy text), green on navy 4.65 (the highlighted word, navy surfaces only), blue on white 4.84, `brand-green-alt` on white 3.24 (graphics and large text only, so the progress bars use it). White on green is 2.93, green text on white 2.93 and blue on navy 2.82, so those pairings are banned in the spec and `BrandTest` checks the views for them and recomputes the ratios from the CSS tokens.
118. **Added a derived file, `public/brand/groibe-logo-small.png`** (600 x 151, 15 KB). It is a proportionally resized copy of the supplied wordmark (the ratio is within 0.3%), made with GD, not redrawn. The full 2400 px PNG made dompdf run out of the 128 MB memory limit when several PDFs were built in one process, which shared hosting would hit, and it is also 85 KB for every email. Emails and PDFs use the small copy. Web pages use the SVGs.
119. **Deviation, low: PDFs use DejaVu Sans, not Inter.** dompdf needs static TTF fonts installed, and the repo only has the variable web font. SOW PDFs therefore do not match the brand font. Add static Inter TTF files and register them with dompdf when PDFs matter for the brand.
120. **Deviation, low: emails list Inter first, then the system fonts,** because email clients do not load web fonts.
121. **Not done: no visual check in a browser.** The pages, emails and PDF were checked by tests and a production asset build (`npm run build` passes), not by eye. Look at the home page, a sign-in page, the client and contributor apps, the admin login and a test email on a real screen before launch, especially the admin panel's success buttons (white label on a green shade; Filament picks the shade, so verify it reads clearly).
122. **Not done: the second hero button "Explore Opportunities"** (spec 10.1). Its page, `/opportunities`, belongs to a later pass. The hero has "Join Groibe" and "Need work delivered? Submit a brief".
123. **Cleanup, low:** `components/layouts/auth/card.blade.php` and `split.blade.php` are unused starter-kit layouts (`split` shows random quotes). Delete them. `app/header.blade.php` still has the starter-kit repo and docs links (item 107).
124. **Reference images:** `docs/brand/*.png` are the AI-generated style samples from the brief. They are committed as documentation only, never served or copied from.

## Agreement Templates screen and demo data

125. **Cause of the "missing" screen:** the resource was registered under System, but the local database had no templates (`AgreementTemplateSeeder` never ran there) and the screen could not create any, so it opened as an empty list. Create is now available to the super admin ("New version"). On any other database, `php artisan db:seed --class=AgreementTemplateSeeder` adds the placeholders, and is safe to repeat.
126. **Decision, low: the effective date cannot be in the future.** A version becomes the current one the moment it is published, and the Member Agreement and Client MSA gates read "current" from `is_active`. A future-dated version would need the old one to stay current until then, plus a scheduled switch. Backdating is allowed. Needs a spec decision if future-dated versions matter.
127. **`/terms` and `/privacy` are not built yet** (spec 10.2). The Terms and Privacy templates can be edited and versioned, and the screen says nothing shows them yet. They go live with the public pages.
128. **Demo dataset is smaller than spec 19** by the founder's request: 12 contributors, 4 organizations, 6 briefs, 4 projects. Spec 19 lists 30, 12, 8 and 6. Open roles, applications, reviews and proof-of-work items are not seeded because those models are not built yet. Extend the seeder when they are.
129. **Risk, medium: every demo account shares one documented password** (`docs/DEMO_DATA.md`). Fine on a local machine. On a staging site reachable from the internet, put it behind HTTP auth or change the password constant before seeding, because the repo documents it.
