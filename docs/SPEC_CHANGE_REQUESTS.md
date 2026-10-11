# Spec change requests

Proposed changes only. The spec is edited by the founder, between passes, and logged in spec section 23.

## SCR-001: Taxonomy permissions (spec section 5)

- **Status:** RESOLVED 2026-10-10. Accepted as recommended. Spec section 5 and section 23 updated.
- **Gap:** Section 5 had no row for Categories, Skills or Project Types, but section 13 lists a Taxonomy group in the admin panel.
- **Decision:** `community_manager` has CRUD on Skills and Categories. `delivery_manager` has read on Skills, Categories and Project Types. `super_admin` has everything, including Project Types writes.
- **Code status:** Implemented. `taxonomy.*` (skills, categories) is `delivery_manager` read and `community_manager` CRUD. `project_types.*` is `delivery_manager` read only. `super_admin` has both. See `PermissionMatrix`, `TaxonomyPolicy`, `ProjectTypePolicy` and `PermissionMatrixTest`.

## SCR-002: Organization write access for client roles (spec sections 4, 5, 12)

- **Status:** RESOLVED 2026-10-10. Accepted as recommended. Spec section 5 and section 23 updated.
- **Gap:** Section 5 gave clients "RU own org" without saying which org roles may update. Section 12 says only the owner invites members.
- **Decision:** `owner` and `manager` may update the organization. `viewer` is read-only. Only `owner` may invite team members.
- **Code status:** Implemented in Pass 1 (`OrganizationPolicy::update`, `::inviteMember`) and covered by tests.

## SCR-003: Admin user management permission (spec sections 4, 13)

- **Status:** RESOLVED 2026-10-10. Accepted as recommended. Spec section 5 and section 23 updated.
- **Gap:** "Admin Users" is a People resource in section 13, but section 5 had no row for it.
- **Decision:** `admin_users.*` is `super_admin` only. Only a holder of `admin_users.update` can change a user's role or admin permission sets, so no other admin can promote anyone.
- **Code status:** Implemented in Pass 1 (`UserPolicy`, `UserForm`, `UserResource` query scope) and covered by tests.

## SCR-004: Client users in the permission matrix (spec section 5)

- **Status:** RESOLVED 2026-10-10. Accepted as recommended. Spec section 5 and section 23 updated.
- **Gap:** The matrix had "Contributor (others)" and "Organization" but no row for client user accounts.
- **Decision:** a `clients.*` permission set mirroring the Organization row for admin roles: `delivery_manager` CRU, `community_manager` R, `finance_ops` R, `super_admin` all.
- **Code status:** Implemented in Pass 1 (`PermissionMatrix`, `UserPolicy`) and covered by tests.

## SCR-005: Brief "scoped" transition (spec section 6.1)

- **Status:** ACCEPTED 2026-10-10. Accepted as recommended (option a). Spec sections 6.1 and 23 updated.
- **Gap:** 6.1 lists `in_review -> scoped -> converted`, but no action triggered `scoped`.
- **Decision:** Add an explicit admin "mark scoped" action (`in_review -> scoped`). Convert to Project requires `scoped`.
- **Code status:** Pass 2.

## SCR-006: SOW rejection (spec section 6.3)

- **Status:** ACCEPTED 2026-10-10. Accepted as recommended (option b). Spec sections 6.3 and 23 updated.
- **Gap:** 6.3 lists `rejected`, but the permission matrix gives clients only "R org, accept".
- **Decision:** No client reject action in v1. The `rejected` enum case is kept but unused. Clients message admin, and admin supersedes the SOW.
- **Code status:** Pass 2. No reject Action is built.

## SCR-007: SOW edge cases (spec sections 6.3, 8)

- **Status:** ACCEPTED 2026-10-10. Accepted as recommended. Spec sections 6.3 and 23 updated.
- **Gap:** The spec did not say what happens when a SOW is edited after being sent or accepted, or what `{{milestones_table}}` renders before milestones exist (Pass 4).
- **Decision:** An accepted SOW is immutable. Editing a sent SOW supersedes it and the project stays `sow_sent`. `{{milestones_table}}` renders an empty-state line until milestones exist.
- **Code status:** Pass 2.

## SCR-008: Project on_hold exit and reason storage (spec sections 6.2, 7.3)

- **Status:** ACCEPTED 2026-10-10. Option (a). Spec sections 6.2, 7.3 and 23 updated.
- **Gap:** 6.2 adds `on_hold` as a side state from `staffing` and `active`, but names no way out. Today the only exit is `cancelled`, so a held project cannot resume. Separately, 6.2 requires a cancel reason, but `projects` has no column for it, and there is nowhere to remember which state a held project came from.
- **Options:**
  - (a) Add `projects.status_before_hold` (nullable string). A new "resume" Action returns the project to that state and clears the column.
  - (b) Resume always returns to `staffing`. No new column, but an `active` project loses its place.
- **Recommendation:** (a). Keep hold and cancel reasons in the activity log for v1, which is where they live now.
- **Decision:** add `projects.status_before_hold`. Hold records the state it came from, and a resume Action returns the project there and clears the column. Reasons stay in the activity log.
- **Code status:** Implemented (`PutProjectOnHold`, `ResumeProject`, migration, admin Resume action, tests).

## SCR-009: Draft projects hidden from clients (spec sections 5, 22)

- **Status:** ACCEPTED 2026-10-10. Spec sections 5 (via the section 22 default) and 23 updated.
- **Gap:** Section 5 gives clients "R org" on projects without saying whether that includes projects still being set up.
- **Decision:** Clients do not see a project, or any SOW draft, until the SOW is sent. A `draft` project is internal.
- **Code status:** Implemented in Pass 2 (`ProjectPolicy::view`, `SowPolicy::view`, client project list and dashboard) and covered by tests.

## SCR-010: Client MSA acceptance page (spec section 12)

- **Status:** ACCEPTED 2026-10-10. Spec sections 12 and 23 updated.
- **Gap:** Section 12 put "accept Client MSA" in client registration, but self-registered clients have no organization yet (Pass 1 item 4), and the owner must be the one to accept (spec 8).
- **Decision:** The organization owner accepts the Client MSA on its own page before the first brief or SOW acceptance. Registration stays accounts only.
- **Code status:** Implemented in Pass 2 (`/app/client/agreements/client-msa`, `AcceptClientMsa`, `AgreementGate`) and covered by tests.


## SCR-011: Contributor reinstate and rejected terminal state (spec section 6.10)

- **Status:** ACCEPTED 2026-10-10. Option (a). Spec sections 6.10 and 23 updated.
- **Gap:** 6.10 lists `active -> suspended` but no way out of `suspended`, and none out of `rejected`. Section 13 requires "activate/suspend" bulk actions, which implies reinstating.
- **Options:**
  - (a) Add `suspended -> active` (reinstate). `rejected` stays terminal in v1.
  - (b) Neither exit, so a suspension is permanent.
  - (c) Also allow `rejected -> profile_complete` so a contributor can reapply.
- **Decision:** (a). Reinstate returns a suspended contributor to `active`. `rejected` is terminal in v1. Reasons for reject and suspend stay in the activity log, with no new column.
- **Code status:** Pass 3.

## SCR-012: Per-project team hiding (spec sections 7.3, 12, 22)

- **Status:** ACCEPTED 2026-10-10. Recommended option. Spec sections 7.3, 12 and 23 updated.
- **Gap:** Sections 12 and 22 say admin can hide the team from the client per project, but `projects` had no column for it.
- **Decision:** Add `projects.hide_team` (bool, default false). Clients see the team (first name, role, headline) only once the SOW is accepted and only when `hide_team` is false.
- **Code status:** Pass 4.

## SCR-013: Admin access to submission files (spec sections 5, 7.3)

- **Status:** ACCEPTED 2026-10-10. Recommended option. Spec sections 7.3 and 23 updated.
- **Gap:** The prompt pack says "assigned admin sees all", but the permission matrix gives admin roles global scope, not per-project assignment.
- **Decision:** Any admin holding `submissions.view` (`delivery_manager`, `super_admin`) can read all submission files. Access is not limited to the project's `delivery_manager_id`.
- **Code status:** Pass 4.

## SCR-014: Assignment completion and removal side effects (spec sections 6.2, 6.6)

- **Status:** ACCEPTED 2026-10-10. Recommended option. Spec sections 6.2 and 23 updated. Section 6.6 is unchanged; the rules are stated at project level in 6.2.
- **Gap:** 6.6 has `active -> completed` with no trigger, and does not say what happens to a contributor's open tasks when an assignment is `removed`. It also does not say in which project states a contributor may be invited.
- **Decision:** Invites are allowed only while the project is `staffing` or `active`. Active assignments become `completed` automatically when the project reaches `accepted`. On removal, the contributor's unfinished tasks return to unassigned (`assignment_id` null, status `todo`) and the delivery manager is notified.
- **Code status:** Pass 4.

## SCR-015: Milestone returns to in progress from QA (spec section 6.9)

- **Status:** ACCEPTED 2026-10-10. Option (a). Spec sections 6.9 and 23 updated.
- **Gap:** 6.9 moves a milestone to `in_qa` automatically once every task is `submitted` or `approved`, but has no way back. When QA requests changes on a task, or an assignment removal returns a task to `todo` (6.2), the milestone would sit in `in_qa` with unfinished work.
- **Options:**
  - (a) Add `in_qa -> in_progress`, automatic when any task leaves `submitted` or `approved`.
  - (b) Block removal and QA change requests while the milestone is in `in_qa`.
- **Decision:** (a). The milestone returns to `in_progress` and moves to `in_qa` again by the existing rule once every task is `submitted` or `approved`. A milestone that is `shared` or later is not affected.
- **Code status:** Pass 4.

## SCR-016: Delivered project returns to QA on client changes (spec section 6.2)

- **Status:** ACCEPTED 2026-10-10. Option (a). Spec sections 6.2 and 23 updated.
- **Gap:** 6.2 has no way out of `delivered` except `accepted` or `cancelled`. If the client requests changes on a milestone (6.9) after the project is delivered, contributor work and QA (which run while the project is `active` or `in_qa`) cannot happen, and the project can never reach `accepted`.
- **Options:**
  - (a) Add `delivered -> in_qa`, automatic when the client requests changes on a milestone.
  - (b) Allow work, QA and task creation while the project is `delivered`, with no state change.
  - (c) Add `delivered -> in_qa` as a manual admin action.
- **Decision:** (a). The project returns to `in_qa` when the client requests changes. An admin marks it `delivered` again once every milestone is `shared` or later, by the existing rule.
- **Code status:** Pass 4.

## SCR-017: New brand (spec sections 1, 10.1, 10.3)

- **Status:** ACCEPTED 2026-10-10. Spec sections 1, 10.1, 10.3 and 23 updated. Design rules in `CLAUDE.md` updated to match.
- **Gap:** The founder supplied a brand brief with different colors, a different slogan, a named font and logo files, which contradicted section 10.3 (navy `#001844`, green `#14C352`, blue `#137AE5`) and the tagline in sections 1 and 10.1.
- **Decision:** Adopt the brief. Colors become `brand-navy` `#042B65`, `brand-green` `#0EAE44`, `brand-green-alt` `#25A446`, `brand-blue` `#046CEA` and `brand-teal` `#06A2A7`. The tagline and hero become "Where Ideas Find People". Inter is the only font. The logo files in `public/brand` are used as supplied, and are never retyped, recolored or stretched.
- **Contrast rules added** (measured, not assumed): navy on white 13.7:1; navy on green 4.65:1 (so green buttons use navy text); white on green 2.9:1 (fails AA, never used); green on white 2.9:1 (never text); `brand-green-alt` on white 3.2:1 (large text and graphics only); blue on white 4.8:1 (links on light surfaces); blue on navy 2.8:1 (never); teal on white 3.1:1 (decorative only).
- **Code status:** Applied after Pass 4 as its own change set (tokens, logos, icons, layouts, admin panel, mail and PDF headers) with tests.

## SCR-018: Invite list for `invite_only` open roles (spec sections 2.2, 5, 7.4)

- **Status:** ACCEPTED 2026-10-10 by the founder, option (a). Spec sections 7.4 and 23 updated.
- **Gap:** 2.2 gives open roles a visibility `invite_only`, and section 5 says contributors read open roles "if allowed", but nothing says how a contributor is invited, and section 7 has no table for it.
- **Options:**
  - (a) Add `open_role_invites` (open_role_id, user_id, invited_by, timestamps, unique on role and user). An admin picks active contributors on the role. Only invited contributors see the role in Discover and may apply.
  - (b) The admin creates applications on a contributor's behalf. No table, but the contributor cannot read the role first.
  - (c) Hidden role reachable only by direct link. No table, but the link is the only protection.
- **Decision:** (a). Managing the list needs `open_roles.update`. Invited contributors can see an `invite_only` role only while it is `published`.
- **Code status:** Pass 5.

## SCR-019: Reapplying after a withdrawal (spec sections 6.5, 7.4, 22)

- **Status:** ACCEPTED 2026-10-10 by the founder, option (a), as built in Pass 5. Spec sections 6.5, 22 and 23 updated.
- **Gap:** Section 22 says an application cannot be edited after submission ("withdraw and reapply"), but 7.4 makes (open_role_id, user_id) unique and 6.5 has no way out of `withdrawn`, so a withdrawn contributor could never reapply.
- **Options:**
  - (a) Add `withdrawn -> submitted`, done by the contributor by submitting a fresh application. The existing row is reused with the new answers, and the activity log keeps the earlier ones. A rejected or declined applicant cannot reapply.
  - (b) Drop the unique index and allow many rows per contributor and role, only one of them open.
  - (c) Do not allow reapplying. A withdrawal is final.
- **Recommendation:** (a). It keeps the unique index and the single row per contributor and role, and adds one small edge to 6.5.
- **Code status:** Pass 5.

## SCR-020: Proof of work, reviews and reputation gaps (spec sections 7.5, 9, 10.2, 16)

- **Status:** PROPOSED 2026-10-11, built in Pass 6 on the assumption it is approved. The `organization_stats` columns (item 2) were chosen by the founder in the Pass 6 session ("mirror, via SCR"). Items 1, 3 and 4 are additions made to keep the build working and need a yes or a different answer. Spec sections 7.5 and 23 are not edited until approved.
- **Gap:** Section 7.5 leaves four things open that the build cannot do without.
- **Proposal:**
  1. `proof_of_work.slug` (string, unique, nullable). Section 10.2 gives `/work/{slug}` and section 16 says public models get immutable slugs, but 7.5 lists no slug column. The slug is generated once, on first publication, as the title plus a random suffix, and never changes.
  2. `organization_stats` columns: organization_id (unique), projects_completed, reviews_count, avg_rating, avg_clarity, avg_communication, avg_professionalism, avg_feedback_time, avg_fairness, recomputed_at. Section 7.5 only says "Org equivalent". Admin-only in v1. The organization review uses five criteria (clarity, communication, professionalism, feedback time, fairness), because section 9 lists `communication` among them.
  3. `reputation_facts.tasks_with_due_date`. The founder decided that approved tasks without a due date are left out of the on-time percentage. With only `on_time_tasks` and `tasks_total` there is no honest denominator for it, so the column holds one.
  4. A unique index on `proof_of_work (user_id, project_id)`, so closing a project cannot create two drafts for one contributor. No new column.
- **Decided by the founder in the same session (logged here, no spec text needed beyond this):** internal admin reviews are never shown to the contributor, never counted in reputation and never public; client owners and managers write contributor reviews and set showcase consent, from `accepted` on and still changeable after `closed`; only `completed` assignments get a draft proof or can be reviewed; first pass means the version 1 submission was approved, on time means approved on or before the due date.
- **Code status:** Pass 6.

## SCR-021: Website CMS columns and contact messages (spec sections 7.9, 10.1, 10.2, 13)

- **Status:** PROPOSED 2026-10-11, built in Pass 8 on the assumption it is approved. The founder chose the contact message columns and the "needs review" handling in the Pass 8 session. Spec sections 7.9 and 23 are not edited until approved.
- **Gap:** Section 7.9 lists the CMS tables but gives `contact_messages` no definition, although 10.2 says the contact form stores to the database and 13 lists a Contact Messages screen. It also has no way to mark seeded copy whose wording is not approved yet.
- **Proposal:**
  1. `contact_messages`: id, name, email, subject (nullable), message, ip, user_agent, status (`new|read|handled`, default `new`), handled_by (nullable user), handled_at, email_sent_at (nullable), timestamps. Any move between the three statuses is allowed. The activity log records status moves only, never the message text or sender.
  2. `needs_review` (bool, default false) on `cms_sections`, `testimonials`, `faqs` and `pages`. Content seeded from copy that is still unresolved is stored inactive with this flag, and the admin screens label it "needs review". An admin turns it off when they approve the wording. Nothing flagged is ever public.
  3. A unique index on `featured_items (type, item_id)`, so one item cannot be featured twice. `item_id` has no foreign key: the public read resolves it every time and skips anything missing or no longer public.
  4. A `contact_messages` permission group (view, update; delete reserved to super admin) added to the permission matrix: community manager `ru`, super admin all, nobody else. The other five Website screens use the existing `cms` row of section 5.
  5. A `needs_review` row can never be active (enforced when the model is saved and in the `active` scope), and admins clear the flag with an "Approve wording" action before switching the row on.
- **Decided by the founder in the same session:** the contact form sends to `CONTACT_EMAIL` (config/groibe.php, `contact@groibe.com` in `.env.example`); `/terms` and `/privacy` come from the active agreement template of that type and return 404 until one is published; stats overrides live in the stats section's content; "projects delivered" means projects in `accepted` or `closed`.
- **Code status:** Pass 8.

## SCR-022: Messaging access rules (spec sections 5, 7.8, 14)

- **Status:** ACCEPTED 2026-10-11 by the founder (recommendation accepted, including that client viewers are not in a project thread), built in Pass 7 item 1, and written into the master spec (sections 5, 7.8, 14 and the section 23 change log). The founder had given the privacy rules (participants see only their threads, internal messages never reach clients or contributors, support threads are private to the person and the admin team) and told the Pass 7 session to proceed; the remaining points followed the recommendations from the Pass 7 opener.
- **Gap:** Section 14 names the participants of a project thread but the permission matrix has no Threads row, `thread_participants` has only `last_read_at`, `threads.type` lists `application` with no use, and nothing says who answers support threads or when a project thread starts.
- **Proposal:**
  1. Two permission groups (view, update): `threads` for project conversations, `delivery_manager` `ru` and `super_admin` all; `support` for support threads, `community_manager` `ru` and `super_admin` all. Nobody else, so `finance_ops` has no access to either.
  2. Membership is derived, never stored. A project thread is readable by: admins holding `threads.view`; active contributors with an `active` or `completed` assignment on the project; client `owner` and `manager` of the project's organization (viewers are read-only and have no seat in the conversation). A support thread is readable by the person who started it and admins holding `support.view`. `thread_participants` only keeps each person's `last_read_at`, and a removed contributor loses access at once.
  3. A project thread is created when the SOW is sent (clients do not see a project before then, section 22) and on first use for older projects. One per project. Nothing exists for a `draft` project.
  4. A project thread is read-only for clients and contributors once the project is `closed` or `cancelled`. Admins can always post.
  5. Only admins may post `is_internal` messages. Internal messages are never returned to anyone else by any query, page, notification or file link.
  6. Support threads are started by contributors (in any review state) and clients. Admins answer them and do not start them.
  7. `threads.type = application` is kept and unused in v1, like `rejected` on SOWs (SCR-006).
  8. `messages.created_at` and `thread_participants.last_read_at` store microseconds, so "unread" is exact. `threads.last_message_at` is added for list order.
  9. The `message` notification category is added. The notification carries no message text and no author name.
- **Code status:** Pass 7 item 1.

## SCR-023: Notification preferences and the email log (spec sections 5, 7.8, 13, 14)

- **Status:** ACCEPTED 2026-10-11 by the founder, built in Pass 7 item 2, and written into the master spec (sections 5, 7.8, 14 and the section 23 change log). The founder had not answered the Pass 7 opener question on where preferences live, so the recommendation was followed, then accepted as proposed.
- **Gap:** Section 14 asks for per-category preferences but 7.8 has no table for them. It also lists `email_log` columns without saying who may read it, and the permission matrix has no row for it.
- **Proposal:**
  1. `notification_preferences`: id, user_id, category, database (bool), mail (bool), timestamps. Unique (user_id, category). No row means both channels are on. Categories come from `config('groibe.notifications.categories')`.
  2. Locked categories (`security`) always send both channels. A stored row for them is ignored and the screen and the service refuse to save one as off.
  3. A category switched off on both channels sends nothing.
  4. `email_log` as in 7.8 (user_id nullable, template, to, status `sent|failed`, error, sent_at, timestamps). One row per recipient address. It stores the template (class) name, the address and a trimmed error (500 characters), never the body or subject. A row is written for every message the mailer sends, and for a queued notification email or the contact inbox email that fails after its last try.
  5. A permission group `email_log` (view only), super admin only. Nobody can create, edit or delete a row from the admin.
  6. The bell links to a notification centre at `/notifications` for every signed-in role, and preferences live at `/settings/notifications`.
- **Code status:** Pass 7 item 2.

## SCR-024: Payouts and invoice references (spec sections 5, 6, 7.6, 11, 13, 14, 15.2)

- **Status:** PROPOSED 2026-10-11, built in Pass 7 item 3. The founder answered "go with your recommendations" to the 15 questions put to them, so the points below are decided. The spec text is not edited until the founder accepts this request, as was done for SCR-023.
- **Gap:** Section 7.6 lists `payouts` and `invoice_refs` with statuses but no transitions. The permission matrix has a Payout row and none for invoice references. Nothing says where a payout amount comes from, what a contributor sees, or what an export contains.
- **Proposal:**
  1. **Agreed amount.** The Team tab on a project gets a "Set amount" action for `delivery_manager` and `super_admin` (spec 7.4 `assignments.agreed_amount`). It cannot go below what is already planned or paid for the seat, and cannot be set on a declined or removed seat.
  2. **Planning a payout.** Finance plans a payout for a seat that is `active` or `completed`, has an agreed amount, and sits on a project that is not a `draft`. The amount defaults to the agreed amount. It may link one milestone of the same project. For a `fixed` seat the payouts that are not cancelled may not add up to more than the agreed amount. `hourly` seats have no cap. Currency comes from the seat.
  3. **Payout transitions.** `planned -> approved -> paid`, and `planned | approved -> cancelled`. `paid` and `cancelled` are final. Marking paid needs a transaction reference (6 to 30 letters and digits only, never account or card numbers, spec 17.2) and a payment date that is not in the future, and may record TDS (not more than the amount). Amount, seat and project are locked once approved: a correction is a cancel and a new payout. Cancelling needs a reason, kept in the audit log. Only a `planned` payout can be edited or deleted. The same finance user may plan and approve; every step is in the audit log.
  4. **`payouts.approved_at`** (nullable timestamp, new). Set on approval. It lets a contributor see a payout from approval onward, including one later paid or cancelled, and never one that was only planned.
  5. **Invoice references.** `invoice_refs` also gets `currency` (char 3, default `INR`, per the money convention in section 7) and a unique index on (project_id, zoho_invoice_ref). Transitions: `draft -> sent -> paid`, and `sent -> overdue -> paid`. Sending needs a due date and fills the issue date. A daily job (08:15) moves `sent` to `overdue` once the due date has passed and sends finance one digest. Only a `draft` can be deleted. There is no `cancelled` state. A paid invoice cannot be edited. Invoices adding up to more than the project's contract value show a warning, never a block. `projects.zoho_invoice_ref` is left as it is: `invoice_refs` is the source of truth.
  6. **Permissions.** A new group `invoice_refs` (view, create, update, delete) beside `payouts`. Finance and super admin have full control of both. Delivery managers read both, including internal notes. Community managers, clients and guests have nothing. Approving, paying and cancelling a payout are `payouts.update`. Exports need `import_export.payouts` (finance and super admin), for payouts and invoice references alike.
  7. **Contributor payouts page** (`/app/contributor/payouts`, section 11): read-only, own payouts from `approved` onward, active contributors only. Columns: project, milestone, amount, TDS, net (amount minus TDS, worked out, not stored), status, paid date, UTR reference. Never internal notes, who planned it, or anyone else's payouts. Clients have no payout or invoice page anywhere.
  8. **Notifications.** A `payout` category (can be switched off). The contributor is told when a payout is approved, paid, or cancelled after approval; a plan cancelled before approval is silent. Messages name the project and the status only, with no amount or reference. Finance is told by the daily digest of invoices that just became overdue (project code and invoice number, no amounts).
  9. **CSV export** from the two Finance screens, respecting the filters on screen, up to 5,000 rows (above that the admin is asked to narrow the filters; the queued, emailed-link route of 15.2 is not built). Payout columns: id, project code and title, contributor name, milestone, amount, currency, TDS, net, status, UTR reference, paid date, created date. Invoice columns: project code and title, Zoho invoice, amount, currency, status, issue and due dates. No contributor email or phone and no notes. Text cells that begin with `=`, `+`, `-`, `@`, tab or carriage return get an apostrophe prefix. Each export is written to the audit log with its row count.
- **Code status:** Pass 7 item 3.
