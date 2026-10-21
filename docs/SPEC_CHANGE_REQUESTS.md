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

- **Status:** ACCEPTED 2026-10-12 by the founder (recommendation accepted), built in Pass 6, and written into the master spec (section 7.5 and the section 23 change log). The `organization_stats` columns (item 2) were chosen by the founder in the Pass 6 session ("mirror, via SCR").
- **Gap:** Section 7.5 leaves four things open that the build cannot do without.
- **Proposal:**
  1. `proof_of_work.slug` (string, unique, nullable). Section 10.2 gives `/work/{slug}` and section 16 says public models get immutable slugs, but 7.5 lists no slug column. The slug is generated once, on first publication, as the title plus a random suffix, and never changes.
  2. `organization_stats` columns: organization_id (unique), projects_completed, reviews_count, avg_rating, avg_clarity, avg_communication, avg_professionalism, avg_feedback_time, avg_fairness, recomputed_at. Section 7.5 only says "Org equivalent". Admin-only in v1. The organization review uses five criteria (clarity, communication, professionalism, feedback time, fairness), because section 9 lists `communication` among them.
  3. `reputation_facts.tasks_with_due_date`. The founder decided that approved tasks without a due date are left out of the on-time percentage. With only `on_time_tasks` and `tasks_total` there is no honest denominator for it, so the column holds one.
  4. A unique index on `proof_of_work (user_id, project_id)`, so closing a project cannot create two drafts for one contributor. No new column.
- **Decided by the founder in the same session (logged here, no spec text needed beyond this):** internal admin reviews are never shown to the contributor, never counted in reputation and never public; client owners and managers write contributor reviews and set showcase consent, from `accepted` on and still changeable after `closed`; only `completed` assignments get a draft proof or can be reviewed; first pass means the version 1 submission was approved, on time means approved on or before the due date.
- **Code status:** Pass 6.

## SCR-021: Website CMS columns and contact messages (spec sections 7.9, 10.1, 10.2, 13)

- **Status:** ACCEPTED 2026-10-12 by the founder (recommendation accepted), built in Pass 8, and written into the master spec (sections 5, 7.9, 10.2, 13 and the section 23 change log). The founder chose the contact message columns and the "needs review" handling in the Pass 8 session.
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

- **Status:** ACCEPTED 2026-10-11 by the founder, built in Pass 7 item 3, and written into the master spec (sections 5, 6.12, 6.13, 7.6, 11, 13, 14, 15.2 and the section 23 change log). The founder had answered "go with your recommendations" to the 15 questions put to them.
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

## SCR-025: Imports are CSV only in v1 (spec section 15.1)

- **Status:** ACCEPTED 2026-10-12 by the founder, to be built in Pass 9 item 2, and written into the master spec (section 15.1 and the section 23 change log).
- **Gap:** Section 15.1 says "CSV/XLSX", but the stack has no spreadsheet library and the spec names none.
- **Decision:** Imports accept CSV only. XLSX is deferred to a later release and is logged in `docs/SUGGESTIONS.md`. Exported CSV files start with a UTF-8 byte order mark so Excel reads names correctly.
- **Code status:** Built in Pass 9 item 2 (SUGGESTIONS 245, 254).

## SCR-026: Exports above 5,000 rows are refused (spec section 15.2)

- **Status:** ACCEPTED 2026-10-12 by the founder, to be built in Pass 9 item 3, and written into the master spec (section 15.2 and the section 23 change log).
- **Gap:** Section 15.2 promised a queued export with an emailed link above 5,000 rows, then noted that v1 builds the synchronous path only. The prompt pack asks for queued exports.
- **Decision:** The queued path is not built. Above 5,000 rows an export is refused with a message asking the admin to narrow the filters. This applies to every export, payouts and invoice references included.
- **Code status:** Built. Payout and invoice exports (SCR-024) and the seven other exports (Pass 9 item 3, SUGGESTIONS 251, 254) all refuse above 5,000 rows.

## SCR-027: Import bookkeeping columns (spec sections 7.9, 15.1)

- **Status:** ACCEPTED 2026-10-12 by the founder, to be built in Pass 9 item 2, and written into the master spec (sections 7.9, 15.1 and the section 23 change log).
- **Gap:** `imports` has no place for the column mapping, the dry-run result or the rows skipped.
- **Decision:** `imports` also gets `mapping` (json), `dry_run` (bool) and `skipped_rows` (int). `status` is a backed enum: `uploaded`, `validated`, `queued`, `running`, `completed`, `failed`. Transitions are one Action each; an illegal one throws `IllegalTransition`.
- **Code status:** Built in Pass 9 item 2 (SUGGESTIONS 245, 247).

## SCR-028: Data requests (spec sections 7.9, 17.2)

- **Status:** ACCEPTED 2026-10-12 by the founder, to be built in Pass 9 item 5, and written into the master spec (sections 7.9, 17.2 and the section 23 change log).
- **Gap:** Section 17.2 asks for user data export and deletion requests, processed by an admin, with no table, states or permission.
- **Decision:** New table `data_requests`: id, uuid, user_id, type (`export|deletion`), status (`open|in_progress|completed|rejected`), note, handled_by (nullable), handled_at (nullable), timestamps. Deletion means anonymise and soft delete, never a hard delete, so payouts and the audit trail stay intact. Only `super_admin` may process requests. Every state change is in the audit log.
- **Code status:** Built in Pass 9 item 5 (SUGGESTIONS 271 to 273).

## SCR-029: Two-factor authentication for admins (spec sections 7.1, 17.2, 18)

- **Status:** ACCEPTED 2026-10-12 by the founder, to be built in Pass 9 item 4, and written into the master spec (sections 7.1, 17.2 and the section 23 change log).
- **Gap:** Section 17.2 says "Filament-compatible package or custom" and no storage exists.
- **Decision:** Filament's built-in app authentication (TOTP). `users` gets an encrypted secret and encrypted recovery codes. 2FA is always required for `super_admin`, whatever the `two_factor_admin` flag says. The flag enforces it for every other admin role. An artisan command resets 2FA for one user by email, so a locked-out admin can be recovered from the server; the reset is written to the audit log.
- **Code status:** Built in Pass 9 item 4 (SUGGESTIONS 260). Reset command: `groibe:two-factor-reset`.

## SCR-030: Showcase consent changes are audited (spec section 17.2)

- **Status:** ACCEPTED 2026-10-12 by the founder, to be checked in Pass 9 item 5, and written into the master spec (section 17.2 and the section 23 change log).
- **Gap:** Consent timestamps are stored for agreements. Showcase consent (`projects.showcase_consent`) has no stated audit rule.
- **Decision:** `agreement_acceptances` is enough for agreements. A change to `showcase_consent` is recorded in the activity log. No data model change. A test is added if one is missing.
- **Code status:** Verified in Pass 9 item 5: already met, pinned by `ConsentRecordsTest` (SUGGESTIONS 274).

## SCR-031: Permission to import clients (spec sections 5, 15.1)

- **Status:** ACCEPTED 2026-10-12 by the founder, to be built in Pass 9 item 2, and written into the master spec (section 5 and the section 23 change log).
- **Gap:** The permission matrix lists exports for each role but not who may import clients.
- **Decision:** New permission `import_export.import_clients`, granted to `super_admin` and `delivery_manager` only. Contributor imports follow the existing Import/Export row.
- **Code status:** Built in Pass 9 item 2 (`import_export.import_clients`, SUGGESTIONS 245).

## SCR-032: Email log retention (spec sections 5, 14, 17.1)

- **Status:** ACCEPTED 2026-10-12 by the founder, to be built in Pass 9 item 6, and written into the master spec (sections 5, 14, 17.1 and the section 23 change log).
- **Gap:** The email log holds recipient addresses and grows with every message, and the spec says nobody edits or deletes its rows. It has no retention rule.
- **Decision:** A daily scheduled job deletes `sent` rows older than 90 days and `failed` rows older than 180 days, judged by `created_at`. Nobody can edit or delete a row from the admin; the job is the only deleter. Each run writes the number of rows removed to the audit log.
- **Code status:** Built in Pass 9 item 6 (`groibe:prune-email-log`, daily 03:00).

## SCR-033: Export permissions and columns (spec sections 5, 15.2)

- **Status:** ACCEPTED 2026-10-12 by the founder, to be built in Pass 9 item 3, and written into the master spec (sections 5, 15.2 and the section 23 change log).
- **Gap:** The Import/Export row of the permission matrix names only contributors, projects and payouts. Section 15.2 also lists organizations, clients, briefs, applications and reviews, with no role holding the right to export them. The columns of a people export were not stated either.
- **Decision:**
  1. No new permissions. Exports of organizations, clients, briefs, applications and reviews need `import_export.projects` (delivery manager, super admin). Contributors need `import_export.contributors` (delivery manager, community manager, super admin). Payouts and invoice references keep `import_export.payouts` (SCR-024).
  2. The permission is checked when the export runs, not only when the button shows.
  3. Every export respects the filters and tab on screen, is refused above 5,000 rows (SCR-026), starts with a UTF-8 byte order mark (SCR-025), protects formula-looking cells, and is written to the audit log with its row count.
  4. Lists of people (contributors, clients, applicants) carry name and email, never phone. No export carries internal notes, bios, review comments or other free text. Admin users are never exported.
- **Code status:** Built in Pass 9 item 3 (SUGGESTIONS 251 to 254).

## SCR-034: Housekeeping jobs (spec sections 17.1, 17.4)

- **Status:** ACCEPTED 2026-10-12 by the founder, to be built in Pass 9 item 6, and written into the master spec (sections 17.1, 17.4 and the section 23 change log).
- **Gap:** Section 17.1 lists a stale-invite cleanup, a daily database-size check and a sitemap refresh without saying what each does. Invites are signed links with no rows to clean up. The database limit of the host plan is not in the repo. Uploaded import files hold personal data and were kept forever (SUGGESTIONS 250).
- **Decision:**
  1. **Stale invites are reported, never deleted.** Each day, delivery managers get one in-app notice listing people created by an admin or an import who have no password and were created more than 14 days ago, with a count and the first few names, so they can resend. The same job clears expired password-reset tokens (Laravel's own `auth:clear-resets`). No person is ever deleted by it.
  2. **The database-size check** reads the size of the database each day, writes it to the log, and shows it on the System status widget. When it passes 80% of `DB_SIZE_LIMIT_MB` (default 1024, set to the host plan's limit), super admins get one in-app and email notice a day while it stays above.
  3. **The sitemap refresh** clears the public caches (homepage, stats, menu and sitemap) once a day, so changes that happen with the passing of time, such as a role past its deadline, show up without waiting for an edit.
  4. **Import files are removed 30 days after upload:** the stored CSV and its report are deleted and the report path is cleared; the import row, its counts and its status stay. Temporary uploads left unused for a day are deleted.
- **Code status:** Built in Pass 9 item 6: `groibe:report-stale-invites` (08:30), `groibe:check-database-size` (03:20), `groibe:refresh-public-caches` (03:30) and `groibe:clean-import-files` (03:10), daily.

## SCR-035: Staging must not be indexed (spec sections 10.4, 17.1)

- **Status:** ACCEPTED 2026-10-15 by the founder, recommendation (a). Built and logged in spec sections 10.4, 17.1 and 23.
- **Gap:** `docs/DEPLOY.md` runs a staging copy of the site on its own subdomain, and demo data may be loaded there. Spec 10.4 covers private profiles and the sitemap, but nothing covers a second copy of the whole site. Today `robots.txt` blocks only `/admin`, `/app/`, `/login`, `/register`, `/invite/` and `/livewire`, and it lists the sitemap. Several public controllers send `X-Robots-Tag: index, follow`. A staging address that is linked or guessed can be indexed, and staging pages would compete with production.
- **Options:**
  - (a) App only. When `APP_ENV` is `staging`: `robots.txt` answers `Disallow: /` with no sitemap line, `/sitemap.xml` answers 404, and a middleware sets `X-Robots-Tag: noindex, nofollow` on every web response, replacing the `index, follow` the public controllers set. Production and local are unchanged.
  - (b) Host only. Password-protect the staging subdomain in cPanel (Directory Privacy). No code, and strangers see a login prompt. CONFIRM before relying on it: cPanel writes its rules into the `.htaccess` of the protected folder, and with the document root at `groibe/public` that is a tracked file, so `scripts/deploy.sh` would refuse to run (it stops on uncommitted changes).
  - (c) Both.
- **Note:** `Disallow: /` stops well-behaved crawlers from fetching pages, so they never read the noindex header. The robots rule prevents crawling. The header is the fallback for a crawler that fetches anyway and is what removes a page already listed.
- **Recommendation:** (a). It needs no host feature, works on any host, and is easy to test. Treat (b) as an optional extra once the tracked-file problem is checked on the real host.
- **Spec sections affected:** 10.4 (one added line for non-production copies), 17.1 (the staging environment), 23.
- **Code status:** Built (`NoIndexOnStaging` middleware, `SitemapController`, `StagingNoIndexTest`). Spec sections 10.4, 17.1 and 23 updated.

## SCR-036: Placeholder agreements must not be acceptable (spec sections 6.10, 8, 10.2, 12, 19)

- **Status:** ACCEPTED 2026-10-15 by the founder, recommendation (b). BUILT 2026-10-16. The founder logs it in spec sections 8, 19 and 23.
- **Gap:** The seeder creates all five agreement templates active, with placeholder text (spec 8, 19). Only two of the places that use a template check for that.
  - **Public Terms and Privacy are already safe.** `LegalPageController::published()` ignores a template that `isPlaceholder()` calls a placeholder. `/terms` and `/privacy` answer 404, and the footer menu and the sitemap leave them out until real wording is published (spec 10.2, SCR-021).
  - **The three acceptance documents are not protected.** `AcceptMemberAgreement`, `AcceptClientMsa`, `AgreementGate`, `ProfileCompleteness`, the contributor onboarding step, the Client MSA page, `CreateSow` and `AcceptSow` all use `AgreementTemplate::currentFor()`, which does not look at `isPlaceholder()`. A contributor can accept the placeholder Member Agreement and be activated. A client owner can accept the placeholder Client MSA and then submit briefs and accept SOWs. A SOW can be built from the placeholder template, sent and accepted. Each acceptance is stored as a real record (user, version, time, IP) against text that says "Placeholder".
  - **The marker misses the SOW template.** `isPlaceholder()` looks for "Replace with the approved legal text." The seeded SOW body says "Replace with the approved SOW wording.", so the SOW template is never recognised as a placeholder.
  - No admin screen uses `isPlaceholder()`, so nothing warns the super admin that an active template is still a placeholder.
- **What registration does today when no active template exists:** creating an account (`/register/contributor`, `/register/client`) does not use any agreement and works. A contributor reaches step 4 and sees "The Member Agreement is not published yet. Please check back soon." with no accept button, and cannot become `active` (spec 6.10). A client owner sees "The Client MSA has not been published yet. Please contact Groibe." and cannot submit a brief or accept a SOW (the gate fails closed). An admin who sends a SOW is told "No active SOW template is published." These screens already exist and behave well. They just never trigger while the seeded placeholders are active.
- **Options:**
  - (a) Seed the five placeholders inactive. Every existing "not published" path then applies by itself, and `/terms` and `/privacy` stay 404. Small change, but it protects only a fresh database: a placeholder activated or republished later is accepted again, and the demo seeder and tests that expect active templates need their own active versions.
  - (b) Treat a placeholder as not published in the one lookup that the acceptance paths use (a new `currentPublishedFor()` beside `currentFor()`; the Agreement Templates admin screen keeps `currentFor()` so a super admin can still open and replace the placeholder). Use one marker for all five placeholders, including the SOW template. Every acceptance path, the contributor step, the MSA page and SOW creation then show the existing "not published yet" message until real wording is published. The same rule the public pages already follow.
  - (c) Both.
- **Recommendation:** (b). It is the rule the public pages already use, it guards against a later mistake as well as the first deploy, and it reuses messages that exist. Also change the seeded SOW text to carry the common marker. (a) adds nothing once (b) is in. A string marker can be defeated by deleting the sentence from a body that is still unfinished, so the Agreement Templates screen should also show a "still a placeholder" label (small, in scope of this SCR).
- **For the founder:** staging cannot run the full flows until some text without the marker is published. Publish clearly labelled test wording on staging for that, and the real wording on production.
- **Spec sections affected:** 8 (gating, placeholder bodies), 6.10, 10.2, 12, 19, 23.
- **Code status:** Built as option (b), 2026-10-16.
  - `AgreementTemplate::currentPublishedFor()` returns the active template of a type only when it holds real wording. `currentFor()` stays, and only the model itself still calls it.
  - Every acceptance path now uses `currentPublishedFor()`: `AcceptMemberAgreement`, `AcceptClientMsa`, `AgreementGate`, `ProfileCompleteness`, the contributor onboarding step, the Client MSA page, `CreateSow` and `AcceptSow`. `LegalPageController::published()` uses it too, with no change in behaviour. `AcceptSow` also refuses a SOW whose own template has become a placeholder.
  - One marker for all five placeholders. The seeded SOW text now carries `AgreementTemplate::PLACEHOLDER_NOTE`.
  - The Agreement Templates screen shows a "Still a placeholder / Real wording" badge in the list and a notice in the form while the text carries the marker. A super admin can still open and replace a placeholder.
  - `DemoSeeder` publishes a labelled "demo wording" version of the Member Agreement, Client MSA and SOW template, only while the current version is a placeholder. `tests/Pest.php` `publishAgreements()` removes the marker from those three. Terms and Privacy stay placeholders, so `/terms` and `/privacy` stay 404.
  - Tests: `tests/Feature/Agreements/PlaceholderAgreementTest.php` (25 cases). With only placeholders, acceptance of each of the three documents and SOW creation are refused and nothing is stored; with real wording each works; an old acceptance of a placeholder does not count; the SOW seed text is detected; the admin screen labels templates and still opens a placeholder.
  - Checked: Pint and Larastan clean. Pest run folder by folder: every folder and loose test file exited 0, with no failures. One Deployment test skips on this Windows machine because `bash` is not on the PATH (SUGGESTIONS 317).
  - Open: a text marker can still be deleted from an unfinished body (SUGGESTIONS 319). Staging needs test wording before the full flows can run (SUGGESTIONS 320).

## SCR-037: Database backup before migrations in the deploy script (spec sections 17.4; DEPLOY.md sections 12, 13, 14)

- **Status:** ACCEPTED 2026-10-15 by the founder, recommendation (a), with pre-deploy dumps kept under their own file name and retention and a sanity check on the dump (see Decision). Built. The founder logged it in spec sections 17.4 and 23.
- **Gap:** `scripts/deploy.sh` runs `migrate --force` with no backup. Migrations here are forward-only and rollback is code only (DEPLOY.md 13). The guide tells a person to run `groibe:backup-db` before any release with migrations, but nothing makes them. The first real deploy and every later one rely on memory.
- **Facts:** `groibe:backup-db` returns a failure code if the dump cannot be written, so a script that stops on error stops on it. It keeps only the newest `GROIBE_BACKUP_KEEP` dumps (default 4), so extra dumps before every deploy would push the weekly Sunday dumps out. Files in `storage/app/private` are not in the dump. On the very first deploy the database has no tables, so a dump has nothing to write and would fail.
- **Options:**
  - (a) Before `artisan down` (so a failure leaves the site up) and only when the database already has tables, run `artisan groibe:backup-db`. If it fails, the script stops with a clear message. On a first deploy the script says the backup is skipped because there is no database yet.
  - (b) Same, but only when migrations are pending. Fewer dumps, but one more thing to get wrong.
  - (c) (a) plus an explicit emergency switch (`SKIP_DB_BACKUP=1`) for a hotfix when the disk is full. It weakens the guarantee.
- **Recommendation:** (a). Pre-deploy dumps get their own file name and retention (see Decision), so frequent deploys never push out the weekly dumps. How the script detects "no tables yet" (for example `migrate:status` failing) is checked when it is built.
- **Spec sections affected:** 17.4 (backups), 23. DEPLOY.md sections 12 to 14 and `DeployFilesTest` (it checks the order of steps) follow the decision.
- **Decision (2026-10-15, founder):** (1) Pre-deploy dumps are written as `groibe-predeploy-YYYYMMDD-HHMMSS.sql.gz` in the same backup folder, by `groibe:backup-db` with a new option for the pre-deploy kind. They are pruned on their own, keeping the newest `GROIBE_PREDEPLOY_BACKUP_KEEP` (default 5). The weekly pattern `groibe-db-*.sql.gz` and `GROIBE_BACKUP_KEEP` are unchanged, so neither kind evicts the other. `GROIBE_BACKUP_KEEP` is not raised. (2) The script stops, before `artisan down`, if the command exits with an error, or if the dump file is missing, empty or smaller than 4 KB (amended 2026-10-21 from 10 KB: a freshly migrated and seeded database dumps to about 7 KB gzipped, so 10 KB would refuse a good dump of a site with no real data yet; the first figure was a starting value: a dump of the full schema is far larger, tune it when built). A non-zero exit alone is not trusted. (3) No emergency skip switch. The open choice in the recommendation is closed./
- **Code status:** Built 2026-10-21, rehearsed, not run on the real host (SUGGESTIONS 322). Audit addition 2026-10-21: the script also runs `gzip -t` on the dump (SUGGESTIONS 329). `groibe:backup-db --predeploy`, `GROIBE_PREDEPLOY_BACKUP_KEEP`, the `predeploy_backup` step in `scripts/deploy.sh`, DEPLOY 12 to 14. Tests: `PredeployBackupTest`, `PredeployScriptTest`.

## SCR-038: Forced HTTPS for hosts without Cloudflare (spec sections 17.1, 17.2)

- **Status:** ACCEPTED 2026-10-15 by the founder, recommendation (a). Built. The founder logged it in spec sections 17.1, 17.2 and 23.
- **Gap:** Nothing in the code or `public/.htaccess` redirects `http://` to `https://`. The guide relies on Cloudflare's "Always Use HTTPS" (DEPLOY.md 11), but a staging subdomain, or production before Cloudflare is switched on, has no redirect. The app sends HSTS only on a request that is already secure, and the production template sets `SESSION_SECURE_COOKIE=true`, so a person who opens the plain `http://` address cannot stay signed in.
- **Options, smallest first:**
  - (a) A rule at the top of `public/.htaccess`: if the request is not HTTPS and `X-Forwarded-Proto` is not `https`, answer with a 301 to the same address on `https://`. Runs before PHP, covers every file, is committed so a deploy copies it (option B of the guide too), and cannot loop behind Cloudflare because it honours the forwarded header. Not read by nginx or `php artisan serve`, so local work is unchanged.
  - (b) In the app: a middleware that redirects when the request is not secure, in staging and production. It needs `TRUSTED_PROXIES` to be right behind Cloudflare or it can redirect in a loop, and it does not cover static files.
  - (c) The cPanel "Force HTTPS Redirect" switch. No code, but cPanel writes the rule into the document root `.htaccess`, which is a tracked file with the recommended setup, so `scripts/deploy.sh` would refuse to run (CONFIRM on the host).
- **Recommendation:** (a). Keep Cloudflare's rule too. The guide's smoke test already checks that `http://` redirects.
- **Spec sections affected:** 17.2 (transport security), 17.1, 23. DEPLOY.md sections 3 and 11.
- **Code status:** Built 2026-10-21, rehearsed, not run on the real host (SUGGESTIONS 322). Audit amendment 2026-10-21 (implementation detail, the spec wording still holds): the validation paths `/.well-known/(cpanel-dcv|pki-validation|acme-challenge)/` are not redirected, so certificate issuing and renewal cannot fail on the redirect (SUGGESTIONS 323). The rule is at the top of the rewrite block in `public/.htaccess`; `HttpsRedirectTest` pins its text and position. The redirect itself is proven with `curl -I` on staging (DEPLOY 15).

## SCR-039: Retention settings, ops alerts and the test wording guard (spec sections 17.1, 17.4)

- **Status:** ACCEPTED 2026-10-21 by the founder (SUGGESTIONS 321, items 1 to 6). Built. The founder logged it in spec sections 17.1, 17.4 and 23.
- **Gap:** DEPLOY section 18 lists retention rules for `failed_jobs`, `job_batches`, read notifications and the audit log that no job applies. Nothing reminds a super admin of a data request that is left waiting. The System status widget calls cron "stopped" after 5 minutes but raises no alert. Nothing stops test agreement wording (DEPLOY section 22) from being published on production.
- **Decision:**
  1. **Retention settings.** Four keys in the `settings` table, each a whole number of days of 1 or more, with these defaults when no row exists or the value is not valid: `retention.failed_jobs_days` 30, `retention.job_batches_days` 30, `retention.notifications_read_days` 180, `retention.activity_log_days` 365. A super admin changes them in System, Settings.
  2. **Daily jobs, in the application time zone:** `groibe:prune-queue-history` (03:40) deletes `failed_jobs` by `failed_at` and `job_batches` by `created_at`; `groibe:prune-notifications` (03:45) deletes notifications that have been read, by `created_at`, and never an unread one; `groibe:prune-activity-log` (03:50) runs Spatie's `activitylog:clean` with the setting. Each writes the number removed, even when none, to the audit log (the activity log run writes its entry after cleaning, so the entry itself is kept).
  3. **Data request reminder.** `groibe:remind-data-requests` (daily 08:45) sends super admins one in-app and email notice a day while any request is `open` or `in_progress` and was made more than 7 days ago (`groibe.ops.data_request_reminder_days`). It carries the count and the age of the oldest, never a name or a request. It is in the locked `security` category, like the data request notices.
  4. **Cron alert.** A cron heartbeat older than 10 minutes counts as stopped. The System status widget then shows an alert line saying how many minutes it has been, and that queued email and every scheduled job are stopped. "No run recorded yet" is not an alert. The widget also shows how many data requests have waited more than 7 days.
  5. **Test wording guard.** `AgreementTemplate::TEST_WORDING_BANNER` is `TEST TEXT, NOT LEGAL WORDING`. When `APP_ENV` is `production`, publishing a version whose body holds the banner (any letter case) is refused with a message on the body field, and nothing is saved. On any other environment it is allowed. In production the System status widget also shows an alert, with the template names, while an active template holds the banner.
  6. **Data exports.** A data export file is sent as a password-protected zip with the password sent by a different route. Off-server copies keep the last 8 weekly dumps and the last 3 monthly file copies. Both are procedure (OPERATIONS, DEPLOY) and need no code.
- **Not changed:** the 14-day log retention, the email log rules (SCR-032), import files (SCR-034) and the dump retention (`GROIBE_BACKUP_KEEP`).
- **Spec sections affected:** 17.1, 17.4, 23.
- **Code status:** Built 2026-10-21 (SUGGESTIONS 321). Tests: `TestWordingGuardTest`, `RetentionTest`, `DataRequestReminderTest`, `CronAlertTest`, `ScheduleTest`.

### SCR-039 amendment (2026-10-21): minimums for the retention settings

- **Status:** ACCEPTED 2026-10-21 by the founder (audit finding, SUGGESTIONS 326). Built 2026-10-21. Spec 17.4 and section 23 updated.
- **Gap:** SCR-039 accepted any whole number of 1 or more for the four retention settings. A typo such as `3` for `retention.activity_log_days` would delete the audit trail older than 3 days that night, and spec 17.2 relies on the trail.
- **Decision:** Each setting has a minimum: `retention.failed_jobs_days` 7, `retention.job_batches_days` 7, `retention.notifications_read_days` 30, `retention.activity_log_days` 90. A value below its minimum is ignored and the default is used (30, 30, 180 and 365), the same as a missing row or a value that is not a whole number. The Settings screen (the Value field in System, Settings) says this, with the minimums and defaults read from `config/groibe.php`. The minimums are `groibe.ops.retention_min`.
- **Also from the same audit (no spec change needed):** the test wording check reads the text a person would see (SUGGESTIONS 324), and `scripts/deploy.sh` runs `gzip -t` on the pre-deploy dump (SUGGESTIONS 329, a further check under SCR-037; spec 17.4 still says "missing, empty or under 4 KB" and does not yet mention it).
- **Code status:** Built 2026-10-21. Tests: `RetentionTest`, `TestWordingGuardTest`, `PredeployScriptTest`.
