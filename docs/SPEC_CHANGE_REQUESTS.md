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
