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

- **Status:** PROPOSED. Not accepted. The spec is unchanged.
- **Gap:** 6.2 adds `on_hold` as a side state from `staffing` and `active`, but names no way out. Today the only exit is `cancelled`, so a held project cannot resume. Separately, 6.2 requires a cancel reason, but `projects` has no column for it, and there is nowhere to remember which state a held project came from.
- **Options:**
  - (a) Add `projects.status_before_hold` (nullable string). A new "resume" Action returns the project to that state and clears the column.
  - (b) Resume always returns to `staffing`. No new column, but an `active` project loses its place.
- **Recommendation:** (a). Keep hold and cancel reasons in the activity log for v1, which is where they live now.
- **Code status:** Pass 2 builds `PutProjectOnHold` and `CancelProject` (reason required, stored in the activity log). No resume Action exists, and the admin panel offers none. A test pins this so the decision is explicit.
