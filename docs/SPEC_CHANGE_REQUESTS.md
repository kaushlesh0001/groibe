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
