# GROIBE MASTER SPEC v1.0

Status: FROZEN for build. Changes only between build passes, logged in section 23.
Audience: Claude Code (Sonnet 5.5) and any human developer.
Rule: read this whole document before generating any code.

---

## 1. Purpose and positioning

- Groibe is a community-powered professional ecosystem and project delivery platform.
- Target members: skilled Indian professionals. Target clients: mid-market businesses.
- Strategy: "Collective Delivery". Clients buy outcomes from Groibe. Groibe assembles and manages a team of contributors.
- Tagline: "Where Ideas Find People"
- Managed delivery is the CORE. Open applications are a CONTROLLED ADD-ON.
- Groibe (the company, operated by Admin users) is the accountable party to the client. Contributors are accountable to Groibe.
- v1 goals:
  - Run real client projects end to end on the platform.
  - Show proof of work and reputation publicly (with consent).
  - Let admins control everything without developer help.
- v1 is NOT a marketplace with self-serve hiring. Clients never hire contributors directly.

## 2. Product model

### 2.1 The delivery loop (core)

```
Brief -> Scoping -> Project + SOW -> Staffing -> Work (tasks/milestones)
     -> Submission -> Internal QA -> Client review -> Acceptance
     -> Reviews -> Proof of Work -> Reputation
```

- Client submits a **Brief** (what they need).
- Admin scopes it, prices it, and converts it into a **Project** with a **SOW**.
- Client accepts the SOW (digital acceptance).
- Admin **staffs** the project: assigns contributors directly, or publishes **Open Roles** (add-on) and selects applicants.
- Contributors work on **Tasks**. They submit **Submissions**.
- Admin does **internal QA** first. Only approved work is shared with the client.
- Client accepts or requests changes on **Deliverables** at milestone level.
- On close: reviews, proof-of-work entries (with client consent), reputation facts update.

### 2.2 Open Roles (controlled add-on)

- An Open Role is a staffing slot on a Project, or a standalone community opportunity created by Admin.
- Visibility is set by Admin per role: `invite_only`, `members_only` (logged-in contributors), `public` (visible to guests, apply requires login).
- Apply mode: contributors apply with a short form. Only Admin shortlists and selects. Clients may be shown the shortlist only if Admin enables `client_can_view_shortlist` on that project.
- Open Roles can be switched off globally by feature flag `open_roles`.
- Clients cannot create Open Roles. They submit Briefs.

### 2.3 What is deliberately simple in v1

- No payment gateway or escrow. Invoicing happens in Zoho Books. Groibe stores references and statuses only.
- No real-time chat. Messaging is per-project threads with refresh/poll.
- No recommendation engine. Matching is rule-based (skills + availability).
- No composite reputation score. Show evidence counts and averages only.

## 3. Glossary

| Term | Meaning |
|---|---|
| Contributor | A vetted professional who delivers work. DB role: `contributor`. UI label: "Contributor". Legal doc: "Member Agreement". |
| Client | A user belonging to a client Organization. DB role: `client`. |
| Admin | Groibe operator. DB role: `admin`. Sub-roles via permissions. |
| Organization | A client company. Has many client users. |
| Brief | A client's request for work, before scoping. |
| Project | A scoped, contracted engagement with a SOW. |
| SOW | Statement of Work attached to a project, versioned, accepted by client. |
| Milestone | A deliverable-level checkpoint on a project. |
| Task | A unit of work assigned to a contributor within a milestone. |
| Submission | A contributor's delivered work for a task. |
| Deliverable | A client-facing output bundle on a milestone, built from approved submissions. |
| Assignment | A contributor's membership of a project with a role. |
| Open Role | A published staffing slot contributors can apply to. |
| Proof of Work | A public/portfolio record of a completed contribution. |

## 4. Actors and roles

- `guest`: public site only.
- `contributor`: onboarding states `pending_review`, `active`, `suspended`. Only `active` may apply or be assigned.
- `client`: belongs to one Organization. Org roles: `owner`, `manager`, `viewer`.
- `admin`: permission sets via spatie/laravel-permission:
  - `super_admin`: everything, including settings, impersonation, deletes.
  - `delivery_manager`: briefs, projects, staffing, QA, reviews.
  - `community_manager`: contributors, open roles, content, featured items.
  - `finance_ops`: payouts, invoice references, exports.
- Impersonation: `super_admin` only. Every start/stop is logged.

## 5. Permission matrix

Legend: C create, R read, U update, D delete, A approve, own = own records only, org = own organization.

| Resource | Guest | Contributor | Client | Delivery Mgr | Community Mgr | Finance | Super Admin |
|---|---|---|---|---|---|---|---|
| Public pages | R | R | R | R | R | R | R |
| Own profile | - | CRU own | CRU own | - | - | - | all |
| Contributor (others) | R if public | - | R only if assigned to their project (limited fields) | RU | CRUA | R | all |
| Organization | - | - | RU own org (owner and manager update, viewer read-only) | CRU | R | R | all |
| Client users (accounts) | - | - | RU own | CRU | R | R | all |
| Admin users | - | - | - | - | - | - | CRUD |
| Taxonomy (categories, skills, project types) | - | - | - | R | CRUD (skills, categories) | - | all |
| Brief | - | - | CR org (U while draft) | RUA | R | - | all |
| Project | - | R if assigned (limited) | R org | CRUA | R | R | all |
| SOW | - | - | R org, accept | CRU | - | R | all |
| Milestone / Task | - | R, U status on own tasks | R org | CRUD | - | - | all |
| Submission | - | CR own | R shared only | RUA | - | - | all |
| Deliverable | - | - | R org, accept/changes | CRU | - | - | all |
| Open Role | R if public | R if allowed | - | CRU | CRUD | - | all |
| Application | - | CR own, withdraw | - | RUA | RUA | - | all |
| Review | - | C own (after close), R about self | C (after acceptance) | RUA (moderate) | R | - | all |
| Proof of Work | R published | RU own text, toggle visibility | consent toggle | CRUA | RUA | - | all |
| Payout | - | R own, from `approved` (SCR-024) | - | R | - | CRUD (approve, pay and cancel are update) | all |
| Invoice reference | - | - | - | R | - | CRUD | all |
| Thread (project conversation) | - | participant: active contributor with an active or completed seat | participant: org owner and manager | RU (moderate) | - | - | all |
| Thread (support) | - | CR own | CR own | - | RU (answer) | - | all |
| Agreements | - | accept | accept | R | R | R | CRUD |
| Website CMS | - | - | - | - | CRUD | - | all |
| Contact messages | - | - | - | - | RU | - | all (delete: super admin only) |
| Settings, flags | - | - | - | - | - | - | CRUD |
| Audit log | - | - | - | R own actions | R own actions | R own | R all |
| Email log | - | - | - | - | - | - | R (read-only, nobody edits; only the retention job deletes, SCR-032) |
| Import/Export | - | - | - | contributors+projects, plus organizations, clients, briefs, applications and reviews with projects (SCR-033), and import of clients (SCR-031) | contributors | payouts | all |

Enforcement: Laravel Policies for every model. Filament resources must call the same policies. No authorization in controllers by role-name string checks; use `can()` abilities.

## 6. Workflows and state machines

Implement each as a PHP backed Enum plus an Action class per transition (`app/Actions/...`). Transitions validate the current state, check permission, write an activity log entry, fire a Notification. Illegal transitions throw a domain exception.

### 6.1 Brief
`draft -> submitted -> in_review -> needs_info -> in_review -> scoped -> converted` ; also `in_review -> declined`.
- `in_review -> scoped` is an explicit admin action ("mark scoped"). Convert to Project requires `scoped`.
- `converted` means a Project was created from it (link `projects.brief_id`).
- Client can edit only in `draft` and `needs_info`.

### 6.2 Project
`draft -> sow_sent -> sow_accepted -> staffing -> active -> in_qa -> delivered -> accepted -> closed`
- Side states: `on_hold` (from staffing/active), `cancelled` (from any pre-closed state, admin only, reason required).
- Resume: `on_hold -> <the state it was held from>` (staffing or active), recorded in `projects.status_before_hold` and cleared on resume. Hold and cancel reasons are kept in the activity log.
- `staffing -> active` requires at least one active assignment.
- `delivered -> accepted` happens when all milestones are client-accepted.
- `active -> in_qa` is an admin action. `in_qa -> delivered` is an admin action, allowed once every milestone is `shared` or later.
- `delivered -> in_qa` happens automatically when the client requests changes on a milestone, so rework and QA run again before the project is delivered a second time.
- Assignments: invites are allowed only while the project is `staffing` or `active`. Active assignments become `completed` automatically when the project reaches `accepted`. When an assignment is `removed`, the contributor's unfinished tasks return to unassigned (`assignment_id` null, status `todo`) and the delivery manager is notified.
- `closed` triggers: review prompts, proof-of-work drafts, reputation recompute.

### 6.3 SOW
`draft -> sent -> accepted | rejected | superseded`
- Editing a sent SOW creates a new version; the old becomes `superseded`. The project stays `sow_sent`.
- An accepted SOW is immutable.
- `rejected` exists as a state but v1 has no client reject action. Clients message admin, and admin supersedes the SOW.
- `{{milestones_table}}` renders an empty-state line until milestones exist.
- Acceptance stores: user id, org id, SOW version, timestamp, IP, user agent.

### 6.4 Open Role
`draft -> published -> closed | filled | archived`
- `filled` when selected applicants reach `positions_needed`.

### 6.5 Application
`submitted -> shortlisted -> offered -> accepted` ; also `submitted|shortlisted|offered -> rejected` ; `submitted|shortlisted -> withdrawn` ; `offered -> declined`.
- `accepted` creates an Assignment (state `active`) on the linked project.
- `withdrawn -> submitted` (SCR-019): the contributor reapplies by submitting new answers. The existing row is reused and the earlier answers stay in the activity log. A rejected, declined or accepted applicant cannot reapply.

### 6.6 Assignment
`invited -> active -> completed` ; `invited -> declined` ; `active -> removed`.
- Direct assignment by admin starts as `invited`; contributor must accept.

### 6.7 Task
`todo -> in_progress -> submitted -> approved | changes_requested`
- `changes_requested -> in_progress`.
- `approved` is set by Admin after internal QA.

### 6.8 Submission
`submitted -> qa_changes_requested | qa_approved`
- `qa_approved` submissions can be attached to a Deliverable.

### 6.9 Deliverable / Milestone
Milestone: `planned -> in_progress -> in_qa -> shared -> client_accepted | client_changes_requested`
- `shared` means visible to client. Only Admin can share.
- `client_changes_requested` returns the milestone to `in_progress` with a change note; tasks are reopened by Admin.
- `planned -> in_progress` happens automatically when the first task on the milestone starts. `in_progress -> in_qa` happens automatically when every task on the milestone is `submitted` or `approved`. Sharing (`in_qa -> shared`) is an explicit admin action.
- `in_qa -> in_progress` happens automatically when any task on the milestone leaves `submitted` or `approved`: QA requests changes on a task, or an assignment removal returns a task to `todo`.
- Task and submission linkage: QA approve sets the submission to `qa_approved` and the task to `approved`. QA request changes sets the submission to `qa_changes_requested` and the task to `changes_requested`. A resubmission creates a new `submissions` row with `version` + 1.

### 6.10 Contributor onboarding
`registered -> profile_complete -> pending_review -> active | rejected` ; `active -> suspended` ; `suspended -> active` (reinstate)
- `rejected` is terminal in v1.
- Admin reviews and activates. Email verification required before `pending_review`.
- Member Agreement acceptance required before `active`. A Member Agreement that still carries the placeholder marker counts as not published and cannot be accepted (SCR-036, section 8).

### 6.11 Client onboarding
- Self-registration: `registered -> verified_email -> org_pending -> org_verified`.
- Admin-created clients: Admin creates Organization + owner user, sends invite email with signed activation link. Client sets password and accepts Client MSA.
- Client cannot submit a Brief until Org is `org_verified` OR Admin sets `can_submit_briefs`.

### 6.12 Payout (SCR-024)
`planned -> approved -> paid` ; also `planned | approved -> cancelled`. `paid` and `cancelled` are final.
- A payout is planned for an assignment that is `active` or `completed`, has an `agreed_amount`, and sits on a project that is not `draft`. The amount defaults to the agreed amount. It may link one milestone of the same project.
- For a `fixed` seat, payouts that are not cancelled may not add up to more than the agreed amount. `hourly` seats have no cap.
- Marking `paid` needs a transaction reference (6 to 30 letters and digits only, never bank account or card numbers, section 17.2) and a payment date that is not in the future. It may record TDS, which cannot exceed the amount.
- Amount, assignment and project are locked once approved: a correction is a cancel and a new payout. Cancelling needs a reason, kept in the activity log. Only a `planned` payout can be edited or deleted.
- Approving sets `approved_at`. The person who plans a payout may also approve it; every step is in the audit log.
- The contributor is told when a payout is approved, paid, or cancelled after approval (section 14).

### 6.13 Invoice reference (SCR-024)
`draft -> sent -> paid` ; also `sent -> overdue -> paid`. There is no `cancelled` state.
- Sending needs a due date and fills the issue date. A daily job (08:15) moves `sent` to `overdue` once the due date has passed and sends finance one digest. Only a `draft` can be deleted. A paid invoice cannot be edited.
- Invoices adding up to more than the project's `contract_value` show a warning, never a block. `projects.zoho_invoice_ref` is left as it is: `invoice_refs` is the source of truth.

## 7. Data model

Conventions:
- Engine InnoDB, utf8mb4. Bigint auto-increment `id`, plus `uuid` (char 26 ULID) on public-facing models. Soft deletes on users, organizations, projects, briefs.
- Timestamps on every table. Foreign keys with explicit `onDelete` behavior. Index every FK and every status column.
- Money: `decimal(14,2)` plus `currency` char(3) default `INR`.
- Enums stored as strings, cast to PHP Enums.
- JSON columns only for flexible metadata, never for queryable core fields.

### 7.1 Identity and organizations

**users**: id, uuid, name, email (unique), password, role (`admin|contributor|client`), status (`active|pending|suspended`), email_verified_at, last_login_at, timezone, remember_token, two_factor_secret (encrypted, nullable), two_factor_recovery_codes (encrypted, nullable), two_factor_confirmed_at (nullable), deleted_at. (SCR-029)

**profiles** (contributor and client user profile, 1:1 users): user_id, headline, bio, phone, city, state, country, photo_path, linkedin_url, portfolio_url, availability (`available|limited|unavailable`), hours_per_week, work_modes (json: remote/hybrid/onsite), experience_years, languages (json), is_public (bool), slug (unique, nullable), onboarding_state, completed_at.

**organizations**: id, uuid, name, slug, legal_name, gstin (nullable), website, industry, size_band, description, logo_path, city, state, country, status (`pending|verified|suspended`), verified_at, can_submit_briefs, is_public, is_featured, zoho_crm_id (nullable), created_by, deleted_at.

**organization_users**: organization_id, user_id, org_role (`owner|manager|viewer`), designation. Unique (organization_id, user_id).

### 7.2 Skills and taxonomy

**categories**: id, name, slug, type (`skill|industry|project_type`), sort.
**skills**: id, name, slug, category_id, is_active.
**skill_user**: user_id, skill_id, level (`beginner|intermediate|advanced|expert`), years, is_verified (admin), verified_by, verified_at.
**project_types**: id, name, slug, description, is_active.

### 7.3 Briefs, projects, SOW

**briefs**: id, uuid, organization_id, submitted_by, title, problem, desired_outcome, project_type_id, budget_band, timeline_band, target_start, deadline, confidentiality (`standard|confidential`), status, needs_info_note, internal_notes, assigned_admin_id, converted_project_id, submitted_at.
**brief_skills**: brief_id, skill_id.
**brief_files**: use media library (collection `brief_files`, private).

**projects**: id, uuid, brief_id (nullable), organization_id, code (unique, e.g. `GRB-2026-0001`), title, slug, summary, scope_summary, project_type_id, status, contract_value, currency, start_date, due_date, delivery_manager_id, client_can_view_shortlist, hide_team (bool, default false), showcase_consent (`none|anonymous|named`), zoho_invoice_ref (nullable), status_before_hold (nullable), closed_at, deleted_at.
**project_skills**: project_id, skill_id.
**sows**: id, project_id, version (int), status, title, body_html, body_variables (json), total_value, currency, payment_terms, sent_at, accepted_at, accepted_by, accepted_ip, accepted_user_agent, superseded_by. Unique (project_id, version).
**milestones**: id, project_id, title, description, sort, due_date, value (nullable), status, shared_at, client_accepted_at, client_accepted_by, client_note.
**tasks**: id, milestone_id, project_id, assignment_id (nullable), title, description, estimate_hours, due_date, status, approved_at, approved_by.
**task_comments**: id, task_id, user_id, body, is_internal (hides from client).
**submissions**: id, task_id, assignment_id, user_id, note, status, qa_note, reviewed_by, reviewed_at, version.
Submission files: media library, collection `submission_files`, private. Any admin holding `submissions.view` (`delivery_manager`, `super_admin`) can read all submission files.
**deliverables**: id, milestone_id, title, summary, shared_at. **deliverable_submission**: deliverable_id, submission_id.

### 7.4 Staffing

**assignments**: id, project_id, user_id, role_title, status, rate_type (`fixed|hourly`), agreed_amount, currency, start_date, end_date, invited_at, accepted_at, completed_at, removed_reason.
Unique (project_id, user_id) where status in active states (enforce in code).
**open_roles**: id, uuid, project_id (nullable), title, slug, summary, description, tasks_list (json array of strings), learning_outcomes (json), project_type_id, work_mode, est_hours_min, est_hours_max, duration_label, compensation_type (`paid|stipend|unpaid_learning|other`), compensation_note, visibility, positions_needed, status, deadline, is_featured, created_by, published_at, closed_at.
**open_role_skills**: open_role_id, skill_id.
**open_role_invites** (SCR-018): id, open_role_id, user_id, invited_by (nullable), timestamps. Unique (open_role_id, user_id). The admin-managed list for `invite_only` roles: only listed active contributors see the role and may apply.
**applications**: id, open_role_id, user_id, status, motivation, relevant_experience, availability_note, expected_amount (nullable), proof_of_work_ids (json of ids), admin_note, shortlisted_at, decided_at, decided_by. Unique (open_role_id, user_id).

### 7.5 Proof, reviews, reputation

**proof_of_work**: id, uuid, user_id, project_id (nullable), title, role_title, summary, contribution_text, skills_demonstrated (via pivot), outcome_metrics (json), visibility (`private|members|public`), status (`draft|pending_approval|published|hidden`), client_display (`anonymous|named`), approved_by, published_at, slug (string, unique, nullable). The slug is generated once, on first publication, as the title plus a random suffix, and never changes (SCR-020). Unique (user_id, project_id), so closing a project cannot create two drafts for one contributor.
**proof_of_work_skill**: proof_of_work_id, skill_id.
**reviews**: id, project_id, reviewer_id, reviewee_type (`user|organization`), reviewee_id, direction (`client_to_contributor|contributor_to_client|admin_to_contributor`), quality, communication, reliability, timeliness (1-5 each, nullable for org direction use the org criteria fields), clarity, professionalism, feedback_time, fairness, comment, status (`pending_moderation|published|hidden`), published_at. Unique (project_id, reviewer_id, reviewee_type, reviewee_id).
**reputation_facts** (per contributor, recomputed by job): user_id, projects_completed, tasks_approved_first_pass, tasks_total, on_time_tasks, reviews_count, avg_rating, verified_skills_count, tasks_with_due_date, recomputed_at. `tasks_with_due_date` is the denominator of the on-time percentage: approved tasks without a due date are left out of it (SCR-020).
**organization_stats** (per client organization, recomputed by the same job, admin-only in v1): organization_id (unique), projects_completed, reviews_count, avg_rating, avg_clarity, avg_communication, avg_professionalism, avg_feedback_time, avg_fairness, recomputed_at. An organization review uses five criteria: clarity, communication, professionalism, feedback time, fairness (SCR-020).

### 7.6 Money tracking (references only)

**payouts**: id, assignment_id, user_id, project_id, amount, currency, status (`planned|approved|paid|cancelled`), milestone_id (nullable), reference (UTR / txn ref), tds_amount (nullable), approved_at (nullable, set on approval, SCR-024), paid_at, notes, created_by. Net is worked out as amount minus TDS, never stored. `reference` holds a transaction reference only.
**invoice_refs**: id, project_id, zoho_invoice_ref, amount, currency (char 3, default `INR`), status (`draft|sent|paid|overdue`), issued_at, due_at. Unique (project_id, zoho_invoice_ref). (SCR-024)

### 7.7 Agreements

**agreement_templates**: id, type (`member_agreement|client_msa|sow_template|privacy|terms`), version, title, body_html, is_active, effective_from.
**agreement_acceptances**: id, template_id, user_id, organization_id (nullable), project_id (nullable), accepted_at, ip, user_agent.

### 7.8 Communication

**threads**: id, uuid, project_id (nullable), type (`project|support|application`), subject, created_by, last_message_at (nullable), timestamps. One `project` thread per project. `application` is kept and unused in v1. **thread_participants**: thread_id, user_id, last_read_at. It holds read markers only: who may see a thread is worked out from live data (section 14), never stored. **messages**: id, thread_id, user_id, body, is_internal (visible to admin only), created_at, updated_at. `messages.created_at` and `last_read_at` keep microseconds so unread counts are exact. Message files: media library, private. (SCR-022)
**notifications**: use Laravel's database notifications table, plus an `email_log` table and a `notification_preferences` table (SCR-023).
**email_log**: id, user_id (nullable), template (class name), to, status (`sent|failed`), error (nullable, trimmed to 500 characters), sent_at (nullable), timestamps. One row per recipient address. It never stores the message body or subject. A row is written for every message the mailer sends, and for a queued notification email or the contact inbox email that fails after its last try.
**notification_preferences**: id, user_id, category, database (bool), mail (bool), timestamps. Unique (user_id, category). No row means both channels are on. Categories are listed in `config/groibe.php`.

### 7.9 Website CMS and ops

**cms_sections**: id, key (e.g. `hero`, `how_it_works`), title, content (json), sort, is_active.
**featured_items**: id, type (`open_role|contributor|organization|proof_of_work|testimonial|project`), item_id, sort, is_active. Unique (type, item_id), so one item cannot be featured twice. `item_id` has no foreign key: the public read resolves it every time and skips anything missing or no longer public (SCR-021).
**testimonials**: id, author_name, author_title, organization_name, quote, photo_path, is_active, sort.
**faqs**: id, audience (`general|contributor|client`), question, answer, sort, is_active.
**pages**: id, slug, title, body_html, meta_title, meta_description, is_active.
**needs_review** (bool, default false) is also kept on `cms_sections`, `testimonials`, `faqs` and `pages` (SCR-021). Content seeded from copy whose wording is not approved yet is stored inactive with this flag, and the admin screens label it "needs review". A `needs_review` row can never be active (enforced when the model is saved and in the `active` scope), and an admin clears the flag with an "Approve wording" action before switching the row on. Nothing flagged is ever public.
**contact_messages** (SCR-021): id, name, email, subject (nullable), message, ip, user_agent, status (`new|read|handled`, default `new`), handled_by (nullable user), handled_at, email_sent_at (nullable), timestamps. Any move between the three statuses is allowed. The activity log records status moves only, never the message text or sender. The message is stored first and emailed after; `email_sent_at` shows whether the team was told.
**settings**: key/value (typed) store with cache.
**feature_flags**: key, enabled, notes. Keys listed in section 18.
**activity_log**: from spatie/laravel-activitylog (admin audit trail; includes impersonation events).
**imports**: id, type, file_path, status (`uploaded|validated|queued|running|completed|failed`), mapping (json), dry_run (bool), total_rows, success_rows, failed_rows, skipped_rows, error_report_path, created_by. (SCR-027)
**data_requests** (SCR-028): id, uuid, user_id, type (`export|deletion`), status (`open|in_progress|completed|rejected`), note, handled_by (nullable), handled_at (nullable), timestamps. Deletion means anonymise and soft delete, never a hard delete. Only a super admin processes requests.

### 7.10 Required indexes (minimum)

- users(email unique, role, status)
- organizations(slug unique, status, is_public)
- projects(code unique, status, organization_id, delivery_manager_id)
- briefs(status, organization_id)
- open_roles(status, visibility, published_at)
- applications(open_role_id, user_id unique, status)
- assignments(project_id, user_id, status)
- tasks(project_id, milestone_id, status, assignment_id)
- reviews(reviewee_type, reviewee_id, status)
- proof_of_work(user_id, status, visibility)
- Full-text index (MySQL FULLTEXT) on open_roles(title, summary), proof_of_work(title, summary), profiles(headline, bio).

## 8. Agreements

- Templates are stored in DB, versioned, editable by Super Admin.
- Seed with placeholder bodies for: Member Agreement, Client MSA, SOW template, Terms, Privacy. The real legal text is pasted in by the founder; the code must not hard-code legal text.
- Every seeded placeholder carries the same placeholder marker. A template whose body still carries it counts as not published: Terms and Privacy answer 404, and the Member Agreement, Client MSA and SOW template cannot be accepted or used, with the existing "not published yet" messages shown instead. The Agreement Templates screen labels such a template as still a placeholder and can still open it for replacement (SCR-036).
- SOW template supports variables: `{{client_name}}`, `{{project_title}}`, `{{scope}}`, `{{milestones_table}}`, `{{total_value}}`, `{{payment_terms}}`, `{{start_date}}`, `{{due_date}}`. A renderer substitutes and stores the rendered HTML in `sows.body_html`.
- Gating:
  - Contributor cannot become `active` without accepting the current Member Agreement.
  - Client owner must accept Client MSA before first Brief or SOW acceptance.
  - SOW acceptance is explicit (checkbox + button), recorded in `agreement_acceptances` and `sows`.
- SOW and agreements can be printed or exported to PDF (barryvdh/laravel-dompdf, pure PHP).

## 9. Reputation and proof of work

- On project `closed`:
  - Create a draft ProofOfWork per assigned contributor with `project`, `role_title`, skills from tasks.
  - Contributor edits `contribution_text` and outcome metrics.
  - Admin approves. Publication also requires project `showcase_consent` of `anonymous` or `named`.
  - States as built: `draft -> pending_approval` (the contributor asks for publication), then `pending_approval -> published` (admin approves) or back to `draft` with a note, and `published <-> hidden` (admin). The contributor edits wording only while the item is a draft, and can switch visibility (`private`, `members`, `public`) on their own item at any time unless it is hidden. Choosing `public` does not publish; an admin still approves.
  - `client_display` follows consent: `anonymous` shows "Confidential client, <industry>".
- Reviews:
  - Client reviews each contributor (quality, communication, reliability, timeliness, comment) after project `accepted`.
  - Contributors review the client (clarity of requirements, communication, professionalism, feedback time, fairness) after `closed`.
  - Admin may add an internal review. All reviews pass moderation (`pending_moderation` -> `published`).
  - Org-directed reviews are visible to Admin only in v1 (flag `public_client_reviews` default off).
- Reputation = evidence only. Show counts and averages:
  - projects completed, tasks approved first pass %, on-time %, review count, average rating, verified skills count.
- Recompute via a queued job on project close and on review publish. Cache results in `reputation_facts`.
- No composite score in v1.

## 10. Public site and CMS

Tech: Blade + Livewire + Alpine + Tailwind. Server-rendered, SEO-friendly, fast.

### 10.1 Homepage sections (each toggleable and ordered in Admin)

1. Hero: "Where Ideas Find People" (the word "People" highlighted in green) + two CTAs ("Explore Opportunities", "Join Groibe") and a secondary line for clients ("Need work delivered? Submit a brief").
2. Ecosystem: "One platform. Two sides. One delivery loop." Contributors card, Clients card.
3. How it works: Brief -> Scope -> Staff -> Deliver -> Prove (contributor view and client view tabs).
4. Live open roles (featured, dynamic, hidden if flag `open_roles` off or none).
5. Proof of work showcase (featured, published only).
6. Community: featured contributors (public profiles only).
7. Organizations: logos of featured public organizations.
8. Stats: counts from DB (contributors, organizations, projects delivered), with admin override values.
9. Testimonials.
10. FAQ (tabs by audience).
11. Final CTA.

### 10.2 Public pages

- `/` home
- `/how-it-works`, `/for-contributors`, `/for-clients`, `/about`, `/contact`, `/faq`
- `/opportunities` (list, filter, search) and `/opportunities/{slug}`
- `/contributors` and `/contributors/{slug}` (public profiles only)
- `/organizations` and `/organizations/{slug}` (public only)
- `/work` and `/work/{slug}` (published proof of work)
- `/skills/{slug}`
- `/terms`, `/privacy`, `/{cms-page-slug}`
- `/sitemap.xml`, `/robots.txt`
- Header and footer menus list only pages that exist for visitors, so a menu link never leads to a 404: Opportunities, Contributors, Organizations and Our work follow their feature flags; About appears once an admin publishes it; Terms and Privacy appear once an active template of that type is published. The menu is cached with the homepage and cleared with it.
- `/how-it-works`, `/for-contributors` and `/for-clients` have built-in text. A Page published in Admin with the same address replaces it. `/about` answers 404 until an admin publishes it.
- Contact form stores to DB (`contact_messages`, section 7.9) and emails admin at `CONTACT_EMAIL` (config `groibe.contact_email`). Spam protection: honeypot + throttle. (SCR-021)

### 10.3 Brand and design

- Name: GROIBE in all caps in the logo, "Groibe" in running text.
- Colors (Tailwind tokens): `brand-navy` `#042B65` (primary text, and dark hero, header and footer surfaces), `brand-green` `#0EAE44` (primary action and success), `brand-green-alt` `#25A446` (green on white surfaces, large text and graphics only), `brand-blue` `#046CEA` (links and secondary accent), `brand-teal` `#06A2A7` (decorative accent, never text).
- Color use under WCAG AA: green buttons carry navy text, never white (white on green is 2.9:1). Green is never text on white. Blue links stay on light surfaces (blue on navy is 2.8:1). Navy surfaces carry the white logo, white text, and green for actions and one highlighted word per headline.
- Logo: the files in `public/brand` (SVG on the web, PNG only where SVG is not supported, such as emails and PDFs). Never retype, recolor or stretch the logo. Icons: `public/favicon.ico`, `public/apple-touch-icon.png`, `public/brand/icon-512.png`.
- Tone: modern, calm, professional. Clean white or deep-navy surfaces, rounded corners, lots of whitespace, strong typography, subtle borders, minimal cards, no gradient overload, no stock-photo look. No invented member counts or other numbers.
- Typeface: Inter, self-hosted (no third-party font requests). Bold for headings, regular for body. No other fonts.
- Mobile-first. Dark mode not required in v1.
- Accessibility: WCAG AA contrast, keyboard navigation, labels on all inputs.
- Copy rules: no em dashes, no AI-related language in public copy.

### 10.4 SEO

- Unique title and meta description per page, canonical URLs, Open Graph tags, JSON-LD (`Organization`, `JobPosting`-style data only if applicable, `BreadcrumbList`).
- `/faq` also carries `FAQPage` structured data.
- Private profiles and organizations are `noindex` and not in sitemap.
- A copy of the site that runs with `APP_ENV=staging` is never indexed: `robots.txt` answers `Disallow: /` with no sitemap line, `/sitemap.xml` answers 404, and every web response carries `X-Robots-Tag: noindex, nofollow`, replacing any other value. Production and local are unchanged (SCR-035).

## 11. Contributor app (`/app/contributor`)

Navigation: Dashboard, Discover (open roles), My Applications, My Work (assignments, tasks), Portfolio (proof of work), Reputation, Payouts, Messages, Notifications (the bell and `/notifications`), Profile, Settings. Discover and My Applications show only while the `open_roles` flag is on; Portfolio, Reputation and Payouts are for active contributors.

- **Registration**: step 1 name/email/password + interests; step 2 profile; step 3 skills (with level) and availability; step 4 accept Member Agreement; step 5 submitted for review.
- **Dashboard**: onboarding status, active assignments, tasks due, applications status, recommended open roles (rule-based: skill overlap + availability), reputation facts.
- **Discover**: filters (skill, type, work mode, compensation type, duration), search, role page, apply form.
- **Apply form**: motivation, relevant experience, availability note, expected amount (optional), select proof-of-work items.
- **My Work**: project list, task board (todo/in progress/submitted/approved), task detail with comments and submission upload (files + note), version history.
- **Portfolio**: proof-of-work drafts and published items, edit text, visibility toggle.
- **Reputation**: facts and received reviews, plus "Review client" prompts after close.
- **Payouts view** (`/app/contributor/payouts`, SCR-024): read-only list of the contributor's own payouts from `approved` onward, including ones later paid or cancelled. Columns: project, milestone, amount, TDS, net, status, paid date, transaction reference. Never internal notes, who planned it, or anyone else's payouts. Active contributors only.

## 12. Client app (`/app/client`)

Navigation: Dashboard, Briefs, Projects, Deliverables, Messages, Notifications, Organization, Settings.

- **Registration**: account, organization details. The organization owner accepts the Client MSA on its own page (`/app/client/agreements/client-msa`) before the first brief or SOW acceptance (spec 8). A Client MSA that still carries the placeholder marker counts as not published: the page says so and the gate stays closed (SCR-036, section 8).
- **Dashboard**: active projects with progress, awaiting-your-action items (SOW to accept, deliverables to review), briefs status.
- **Briefs**: create (guided steps: problem, desired outcome, type, skills, budget band, timeline, confidentiality, files), list, view status and admin questions (`needs_info`).
- **Projects**: list, detail (milestones, progress, team shown by first name + role + headline once the SOW is accepted and unless `hide_team` is set), SOW view/accept, change requests.
- **Deliverables**: review shared deliverables, accept or request changes with a note. Only `owner` and `manager` may accept or request changes; `viewer` is read-only.
- **Reviews**: review contributors after acceptance; give showcase consent (none / anonymous / named).
- **Organization**: profile, invite team members (owner only), org roles.
- Clients never see: contributor rates, payouts, internal comments, unapproved submissions, applicant lists (unless enabled).

## 13. Admin panel (Filament, `/admin`)

Use Filament for all admin CRUD. Do not hand-build admin tables and forms.

Navigation groups and resources:

- **Dashboard**: widgets for counts (contributors by state, organizations, briefs by status, projects by status), pipeline funnel, tasks overdue, QA queue, applications awaiting action, reviews awaiting moderation.
- **People**: Contributors (with review queue, verify skills), Clients (users), Organizations, Admin Users, Imports.
- **Delivery**: Briefs, Projects (with relation managers: SOWs, Milestones, Tasks, Assignments, Payouts, Invoices, Threads), QA Queue (submissions awaiting review), Deliverables, Messages (project conversations and support threads, each admin seeing only what their permission allows, SCR-022).
- **Community**: Open Roles, Applications, Proof of Work, Reviews (moderation).
- **Website**: Homepage Sections, Featured Items, Testimonials, FAQs, Pages, Contact Messages. Contact Messages are read and updated by community managers and super admins; only a super admin can delete one, because it holds a visitor's personal data (SCR-021).
- **Taxonomy**: Categories, Skills, Project Types.
- **Finance**: Payouts, Invoice References (exports). Finance and super admin plan, approve, pay, cancel, edit and export; delivery managers read; nobody else sees them, and clients never do (SCR-024). The Team tab on a project has a "Set amount" action (delivery manager, super admin) for a seat's agreed amount, which payouts are planned against. Projects show read-only Payouts and Invoices tabs to staff who may read them.
- **System**: Agreement Templates, Settings, Feature Flags, Audit Log (read-only), Email Log.

Required admin capabilities:
- Create/edit any record. Convert Brief -> Project in one action (pre-fills project, copies skills).
- Send SOW action. Invite contributor to project action. Share milestone with client action.
- Impersonate user (Super Admin only), with banner "Impersonating X - Stop", logged.
- Bulk actions: activate/suspend contributors, export selected.
- Confirmation modals on destructive or state-changing actions.
- Every admin write is captured by the activity log.

## 14. Messaging and notifications

- Project threads: participants are project admins, assigned contributors, and client users. Messages flagged `is_internal` are visible to Admin only. Contributors and clients never talk directly outside a project thread, and Admin can moderate.
- Who is in a project thread is derived from live data (SCR-022): admins holding `threads.view` (delivery manager, super admin); contributors with an `active` or `completed` assignment who are themselves active; and the client organization's `owner` and `manager` (not `viewer`). A removed or suspended person loses access at once.
- A project thread is created when the SOW is sent (clients see nothing before then, section 22), and on first use for older projects. Nothing exists for a `draft` project. It is read-only for clients and contributors once the project is `closed` or `cancelled`; admins can always post.
- Only admins may post `is_internal` messages, and no query, page, notification or file link returns them to anyone else.
- Contributors cannot start threads with clients. Support thread (user <-> admin) is available to every contributor, whatever their review state, and to every client. Only the person who started it and admins holding `support.view` (community manager, super admin) can read it; admins answer support threads and do not start them. The `application` thread type is unused in v1.
- A new-message notification carries no message text and no author name.
- Notifications (database + email, user-configurable per category except security):
  - Brief status changes, SOW sent/accepted, assignment invite, application status, task assigned/changed, submission QA result, deliverable shared, deliverable accepted/changes requested, review requested, new message, payout status change (approved, paid, or cancelled after approval; the text names the project and status only, with no amount or reference), invoice references gone overdue (a daily digest to finance, no amounts), onboarding status change.
- Bell icon with unread count, notifications page (`/notifications`, every signed-in role), mark read and mark all read. Preferences are at `/settings/notifications` (SCR-023).
- Per-category preferences choose the database and email channels separately. Security categories are locked on: they always send both channels and cannot be saved as off. A category switched off on both channels sends nothing.
- The email log (section 7.8) is read-only and super admin only (section 5). A daily job deletes `sent` rows older than 90 days and `failed` rows older than 180 days (SCR-032).
- Messages as built: up to 5,000 characters, up to 5 attached files of 20 MB each (the document allowlist, section 17.2), and 20 messages a minute per person. Threads refresh every 20 seconds while the page is visible. Unread counts show per conversation and as a badge in the sidebar. Names: a client sees a contributor by first name, or as "Project team" when the project hides its team (section 12); a contributor sees a client person by first name and organization; people outside Groibe see staff as "First name (Groibe)"; admins see full names. An inbox shows the time of the latest message the person may see, so internal notes leave no trace for outsiders.
- Notification categories as built: `brief`, `sow`, `project`, `assignment`, `application`, `task`, `submission`, `deliverable`, `message`, `payout`, `review`, `portfolio`, `onboarding`, `security` (locked). Besides the events listed above, notifications also go out for: an assignment answered or removed, work received for QA, a milestone ready to share and the client's answer, a project delivered, accepted and closed, a daily overdue task digest to the delivery manager, an open role invitation, proof-of-work changes, and a review waiting in the moderation queue (to admins).
- Email via Laravel Mail with markdown templates; templates editable only in code in v1 (listed in `resources/views/emails`). Use a `NotificationDispatcher` service so providers can change later.
- Emails sent through the queue (database driver). Failures logged to `email_log`.

## 15. Import and export

### 15.1 Import (Admin, Filament import actions)

Flow: upload CSV (XLSX is deferred, SCR-025) -> map columns -> dry-run validation preview -> import -> downloadable error report. Imports run queued in chunks of 200 rows. Idempotent by email (users) and by name+website or gstin (organizations). Importing clients needs `import_export.import_clients` (delivery manager, super admin, SCR-031).

**Contributors CSV**: name, email, phone, city, state, headline, bio, skills (semicolon-separated names), experience_years, linkedin_url, availability, status (default `pending`).
**Clients CSV**: organization_name, website, industry, size_band, city, state, gstin, contact_name, contact_email, contact_phone, designation, org_role (default `owner` for the first contact, `manager` for others), zoho_crm_id (optional).
- Rows with the same organization_name+website group under one Organization, with multiple users.
- Unknown skills: create as inactive skills and report them; admin reviews.
- Imported users get `status=pending`, no password. Admin triggers "Send invites" (bulk, throttled, queued).
- Invite link: signed URL, expires in 7 days, resendable.

### 15.2 Export

CSV export for: contributors, organizations, clients, briefs, projects, applications, reviews, payouts, invoice refs. Respects permission matrix. Exports run synchronously up to 5,000 rows. Above that the export is refused with a message asking the admin to narrow the filters; the queued path is not built (SCR-026). Every CSV starts with a UTF-8 byte order mark (SCR-025). Exports of organizations, clients, briefs, applications and reviews need `import_export.projects` (delivery manager, super admin), contributors need `import_export.contributors`, payouts and invoice references need `import_export.payouts`. Exports respect the filters on screen and are written to the audit log with their row count. Lists of people carry name and email but never phone, and no export carries notes or free text (SCR-033).
Payout and invoice reference exports (SCR-024) need `import_export.payouts` (finance, super admin), respect the filters on screen, and are written to the audit log with their row count. Payout columns: id, project code and title, contributor name, milestone, amount, currency, TDS, net, status, transaction reference, paid date, created date. Invoice columns: project code and title, Zoho invoice, amount, currency, status, issue and due dates. No contributor email or phone and no notes. Text cells starting with `=`, `+`, `-`, `@`, tab or carriage return are prefixed with an apostrophe so spreadsheets cannot run them.

## 16. URL structure and route files

Route files: `routes/public.php`, `routes/contributor.php`, `routes/client.php`, `routes/auth.php`; admin via Filament panel.

- Public: see section 10.2.
- Auth: `/login`, `/register/contributor`, `/register/client`, `/forgot-password`, `/reset-password/{token}`, `/verify-email`, `/invite/{token}` (signed).
- Contributor: `/app/contributor/*`. Client: `/app/client/*`. Admin: `/admin/*`.
- Middleware groups: `auth`, `verified`, `role:contributor` or `role:client`, `active.contributor` (blocks non-active), `org.verified` where needed.
- Slugs for public models are generated and immutable after publish (redirect table `slug_redirects` if changed).

## 17. Non-functional requirements

### 17.1 Hosting constraints (shared hosting, cPanel)

- Target: PHP 8.3, MySQL/MariaDB, no persistent processes, no Node at runtime, no Redis, no websockets.
- Queue driver `database`, drained by cron: `schedule:run` every minute, which runs `queue:work --stop-when-empty --max-time=50`.
- Cache driver `file` (switch to `database` if file perms are an issue). Sessions `database`.
- Scheduled jobs (what each one does is in SCR-032 and SCR-034): email log retention (daily, SCR-032), reputation recompute, stale-invite cleanup, sitemap refresh, daily DB-size check, notification digests. As built: the reputation recompute is not scheduled; it runs as a queued job on project close and on review publish (section 9). Also as built: the queue drain (every minute), the overdue task digest (daily 08:00) and the overdue invoice marking with its digest to finance (daily 08:15), in the application time zone. Added by SCR-039, daily: `groibe:prune-queue-history` (03:40), `groibe:prune-notifications` (03:45), `groibe:prune-activity-log` (03:50) and `groibe:remind-data-requests` (08:45).
- Assets built locally with Vite and committed or uploaded (`public/build`). The server never runs npm.
- Do not rely on `storage:link` symlinks working. Public media goes to a dedicated public disk under `public/` or is served through a controller. Private files served only via authorized controller routes.
- Keep memory use modest (<256MB per request). Paginate everything. Avoid N+1 (use eager loading; add `Model::preventLazyLoading()` in non-production).
- A staging copy runs on its own subdomain, database and `.env` with `APP_ENV=staging`, and is never indexed (section 10.4, SCR-035).
- `public/.htaccess` redirects plain HTTP to HTTPS, so hosts without Cloudflare also redirect (SCR-038).

### 17.2 Security

- Laravel auth, bcrypt/argon hashing, CSRF, validated Form Requests everywhere, Policies on every model.
- Rate limiting: login (5/min per IP+email), register, contact form, password reset, invite redemption.
- Email verification required. Password rules: min 10 chars, not compromised-checked if available.
- File uploads: allowlist extensions and MIME, size caps (profile photo 2 MB, logo 2 MB, portfolio image 5 MB, documents 20 MB, submissions 25 MB), randomized storage names, no executable types, images re-encoded where possible.
- Private files: `private` disk only; access via signed, authorized routes; never guessable paths.
- Security headers via middleware (HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, a conservative CSP).
- Transport: a request that is not HTTPS is redirected with a 301 to the same address on HTTPS by a rule at the top of `public/.htaccess`. The rule honours `X-Forwarded-Proto: https`, so it cannot loop behind Cloudflare. HSTS is sent only on requests that are already secure (SCR-038).
- Admin: TOTP 2FA through Filament's app authentication, always enforced for `super_admin` and, while `two_factor_admin` is on, for every other admin role. An artisan command resets 2FA for one user by email (SCR-029).
- Sensitive data: do not collect government IDs, bank accounts, or card numbers in v1. Payout `reference` stores only a transaction reference.
- Audit: activity log on all admin writes, role changes, impersonation, agreement acceptances, showcase consent changes, exports. (SCR-030)
- DPDP-oriented privacy: collect minimum data, state purpose, allow user data export and deletion request (admin-processed), consent timestamps stored.

### 17.3 Performance

- Page response target: <500ms server time on public pages with warm cache.
- Cache public lists (open roles, featured items, stats) for 5-15 minutes; bust on change.
- Use Cloudflare in front; set cache headers for static assets.

### 17.4 Observability and ops

- Log channel `daily`, retention 14 days. Exceptions emailed to admin address (throttled).
- Health route `/up` (Laravel default) and an admin "System status" widget (queue backlog, failed jobs, last cron run, database size).
- Backups: host daily backups plus a weekly DB dump command (`php artisan groibe:backup-db`) written outside web root.
- Before a deploy that finds an existing database, `scripts/deploy.sh` writes a pre-deploy dump, named `groibe-predeploy-YYYYMMDD-HHMMSS.sql.gz`, with its own retention (newest `GROIBE_PREDEPLOY_BACKUP_KEEP`, default 5) so it never evicts the weekly dumps. It runs before the site goes into maintenance mode and stops the deploy if the command fails or the dump is missing, empty or under 10 KB. On a first deploy, with no database yet, it is skipped (SCR-037).
- Daily housekeeping (SCR-034): a report to delivery managers of people invited but not activated after 14 days, which deletes nobody, together with removal of expired password-reset tokens; a database-size check that warns super admins at 80% of `DB_SIZE_LIMIT_MB` (default 1024); a refresh of the public caches and sitemap; and removal of the stored file and report of an import 30 days after its upload, and of unused temporary uploads after a day.
- Retention (SCR-039): the days kept are settings in the `settings` table, each a whole number of 1 or more, with defaults used when a row is missing or invalid: `retention.failed_jobs_days` 30 and `retention.job_batches_days` 30 (`failed_jobs` by `failed_at`, `job_batches` by `created_at`), `retention.notifications_read_days` 180 (notifications that have been read, by `created_at`; unread ones are never deleted) and `retention.activity_log_days` 365 (the audit trail, through `activitylog:clean`). Each daily job writes the number removed to the audit log. The email log (SCR-032) and import files (SCR-034) keep their own rules.
- Alerts (SCR-039): a cron heartbeat older than 10 minutes counts as stopped and the System status widget shows an alert saying how long; the widget also shows data requests waiting more than 7 days, and super admins get one in-app and email notice a day (08:45) while one is waiting (count and age of the oldest only, no names). Test agreement wording (the banner `TEST TEXT, NOT LEGAL WORDING`) cannot be published when `APP_ENV` is `production`, and the widget shows an alert on production while an active template holds it.

### 17.5 Quality bar

- Pest or PHPUnit feature tests for: every state transition, every policy rule, registration/onboarding flows, imports, and file access control.
- Factories and seeders for every model.
- Code style: Laravel Pint. Static analysis: Larastan level 5 minimum.
- Conventions: thin controllers/Livewire components, business logic in Action classes (`app/Actions`), Enums in `app/Enums`, no logic in Blade.

## 18. Feature flags

Stored in `feature_flags`, cached, toggled in Admin. Defaults:

| Key | Default | Controls |
|---|---|---|
| `open_roles` | on | Open roles board, applications, homepage section |
| `public_directories` | on | Public contributor and organization directories |
| `proof_of_work_public` | on | Public `/work` pages |
| `messaging` | on | Project threads and support threads |
| `contributor_reviews_of_clients` | on | Contributors can review clients (admin-only visibility) |
| `public_client_reviews` | off | Show client reviews publicly |
| `client_can_view_shortlist` | off | Global default for the per-project setting |
| `self_serve_client_signup` | on | Public client registration (else invite-only) |
| `contributor_signup` | on | Public contributor registration |
| `two_factor_admin` | on | Enforce 2FA for admins |

## 19. Seed data

- Roles and permissions per section 4 and 5.
- Super admin user from `.env` (`ADMIN_EMAIL`, `ADMIN_PASSWORD` set at deploy; never committed).
- Categories and 40+ skills across: Research, Marketing, Content, Design, Data & Analytics, Technology, Operations, Finance, Sales.
- Project types: Market Research, Dashboard/Reporting, Content & Social, Process Automation, Website/App Build, Financial Modelling, Operations Support.
- Agreement templates (placeholders, all five carrying the same placeholder marker, SCR-036), FAQs (10 general, 6 contributor, 6 client), homepage CMS sections with default copy, feature flags.
- Demo dataset (local/staging only, `DemoSeeder`): 12 contributors (active, pending review and suspended), 4 organizations with 7 client users, 6 briefs, 8 projects (draft, SOW sent, staffing, active, three accepted, one closed), 4 open roles with 4 applications, 5 reviews, 3 proof-of-work items, featured picks and two clearly fake testimonials. It also publishes labelled demo wording for the Member Agreement, Client MSA and SOW template (SCR-036). Never runs in production. Full list in `docs/DEMO_DATA.md`.

## 20. Acceptance criteria (summary; per-pass details live in the prompt pack)

- A client can register, get verified, submit a brief, receive and accept a SOW, see progress, accept deliverables, review contributors, and consent to showcase.
- Admin can convert the brief to a project, staff via direct invite and via open role, QA submissions, share deliverables, record payouts and invoice refs, close the project.
- A contributor can register, be approved, accept the Member Agreement, apply to an open role, work tasks, submit work, and see published proof-of-work and reputation facts.
- Homepage sections are fully controlled from Admin with no code change.
- Import of contributors and clients works with a dry run and an error report.
- All policy tests pass: no cross-organization data leaks, no client access to internal data, no contributor access to other contributors' private data.
- Production deploy runs on shared hosting with cron-driven queue.

## 21. Out of scope for v1

- Payment gateway, escrow, GST invoicing generation (Zoho Books handles it).
- Mobile apps, real-time chat, video calls.
- AI features and recommendation engines (keep a `app/Services/Ai` placeholder interface only).
- Public client reviews, composite reputation scores, mentor/partner roles.
- Multi-language and multi-currency UI (currency field exists; INR only in UI).
- Multi-tenant subdomains.

## 22. Defaults chosen where the founder has not decided

- Clients see contributors by first name, role, and headline. Admin can hide per project.
- Contributor identity is not shown to clients before SOW acceptance.
- Projects are INR-only.
- Clients do not see a project, or any SOW draft, until the SOW is sent (project status `draft` is internal).
- Contributors may hold multiple active assignments.
- Applications cannot be edited after submission. To change one, withdraw it and reapply (6.5, SCR-019).
- Reviews are moderated before publishing.

## 23. Change log

| Date | Section | Change | Approved by |
|---|---|---|---|
| (initial) | all | v1.0 frozen | Founder |
| 2026-10-10 | 5 | SCR-001: added Taxonomy row (delivery_manager R, community_manager CRUD on skills and categories, super_admin all) | Founder |
| 2026-10-10 | 5, 12 | SCR-002: client org update by owner and manager, viewer read-only; only owner invites members | Founder |
| 2026-10-10 | 5 | SCR-003: added Admin users row, super_admin only; only it may change roles or admin permission sets | Founder |
| 2026-10-10 | 5 | SCR-004: added Client users row mirroring the Organization row for admin roles | Founder |
| 2026-10-10 | 6.1 | SCR-005: added explicit "mark scoped" action; Convert requires `scoped` | Founder |
| 2026-10-10 | 6.3 | SCR-006: no client reject action in v1; `rejected` state kept, unused | Founder |
| 2026-10-10 | 6.3, 8 | SCR-007: accepted SOW immutable; editing a sent SOW keeps project at `sow_sent`; `{{milestones_table}}` empty-state until milestones exist | Founder |
| 2026-10-10 | 6.2, 7.3 | SCR-008: added `projects.status_before_hold` and a resume transition from `on_hold` | Founder |
| 2026-10-10 | 5, 22 | SCR-009: clients do not see draft projects or draft SOWs until the SOW is sent | Founder |
| 2026-10-10 | 12 | SCR-010: Client MSA is accepted by the owner on its own page, not in registration | Founder |
| 2026-10-10 | 6.10 | SCR-011: added `suspended -> active` (reinstate); `rejected` is terminal in v1 | Founder |
| 2026-10-10 | 7.3, 12 | SCR-012: added `projects.hide_team`; clients see the team only after SOW acceptance and when not hidden | Founder |
| 2026-10-10 | 7.3 | SCR-013: any admin with `submissions.view` reads all submission files | Founder |
| 2026-10-10 | 6.2 | SCR-014: invites only in `staffing`/`active`; assignments auto-complete on `accepted`; removal unassigns open tasks | Founder |
| 2026-10-10 | 6.2, 6.9, 12 | Pass 4 decisions, no SCR: admin-triggered project `in_qa` and `delivered`; automatic milestone `in_progress` and `in_qa`; task and submission QA linkage; only client `owner` and `manager` accept deliverables | Founder |
| 2026-10-10 | 6.9 | SCR-015: added `in_qa -> in_progress`, automatic when a task leaves `submitted` or `approved` (QA changes requested, or removal returns a task to `todo`) | Founder |
| 2026-10-10 | 6.2 | SCR-016: added `delivered -> in_qa`, automatic when the client requests changes on a milestone | Founder |
| 2026-10-10 | 1, 10.1, 10.3 | SCR-017: new brand: colors (navy `#042B65`, green `#0EAE44`, blue `#046CEA`, plus green-alt and teal), Inter, logo files and rules, contrast rules, tagline "Where Ideas Find People" | Founder |
| 2026-10-10 | 2.2, 7.4 | SCR-018: added `open_role_invites`, the admin-managed invite list for `invite_only` open roles | Founder |
| 2026-10-10 | 6.5, 7.4, 22 | SCR-019: added `withdrawn -> submitted` (reapply reuses the same application row); rejected, declined and accepted applicants cannot reapply | Founder |
| 2026-10-11 | 5, 7.8, 14 | SCR-023: added `notification_preferences`; `email_log` rules (one row per recipient, no body, failures logged, super admin read-only); preference and locked-category rules; notification centre and preferences pages | Founder |
| 2026-10-11 | 5, 7.8, 14 | SCR-022: messaging access rules: `threads` and `support` permissions; conversation membership derived from live project data; project thread opens with the SOW and is read-only after close or cancel; admin-only internal messages; support thread privacy; unused `application` type; microsecond read markers | Founder |
| 2026-10-11 | 5, 6.12, 6.13, 7.6, 11, 13, 14, 15.2 | SCR-024: payout and invoice reference state machines; `payouts.approved_at`, `invoice_refs.currency` and a unique invoice number per project; agreed amount cap and Set amount action; finance, delivery manager and contributor visibility; payout notifications without amounts; overdue invoice job; CSV export contents and formula protection | Founder |
| 2026-10-12 | 7.5 | SCR-020: `proof_of_work.slug` (unique, immutable) and unique (user_id, project_id); `organization_stats` columns and the five organization review criteria; `reputation_facts.tasks_with_due_date` | Founder |
| 2026-10-12 | 5, 7.9, 10.2, 13 | SCR-021: `contact_messages` table; `needs_review` on `cms_sections`, `testimonials`, `faqs` and `pages` (never active until approved); unique (type, item_id) on `featured_items`; `contact_messages` permission group (community manager `ru`, delete super admin only) | Founder |
| 2026-10-12 | 15.1 | SCR-025: imports are CSV only in v1, XLSX deferred; exported CSV starts with a UTF-8 BOM | Founder |
| 2026-10-12 | 15.2 | SCR-026: exports above 5,000 rows are refused, the queued path is not built | Founder |
| 2026-10-12 | 7.9, 15.1 | SCR-027: `imports` gains `mapping`, `dry_run`, `skipped_rows` and a status enum | Founder |
| 2026-10-12 | 7.9, 17.2 | SCR-028: `data_requests` table; deletion is anonymise and soft delete; super admin only | Founder |
| 2026-10-12 | 7.1, 17.2, 18 | SCR-029: TOTP 2FA via Filament app authentication, secret and recovery codes on `users`, always on for super admin, flag governs other admins, artisan reset command | Founder |
| 2026-10-12 | 17.2 | SCR-030: showcase consent changes are written to the activity log | Founder |
| 2026-10-12 | 5, 15.1 | SCR-031: permission `import_export.import_clients` for delivery manager and super admin | Founder |
| 2026-10-12 | 17.1, 17.4 | SCR-034: what the stale-invite, database-size, sitemap and import-file jobs do, and the System status database size | Founder |
| 2026-10-12 | 5, 15.2 | SCR-033: which permission each export needs, and that people lists carry name and email, never phone, and no notes or free text | Founder |
| 2026-10-12 | 5, 14, 17.1 | SCR-032: daily retention job for the email log, `sent` rows after 90 days and `failed` rows after 180 days | Founder |
| 2026-10-12 | 9, 10.2, 10.4, 11, 13, 14, 17.1 | Recorded as built, no new behaviour: proof of work state moves and contributor rights; header and footer menus and built-in pages; `FAQPage` data; Payouts in contributor navigation; the admin Messages screen; message limits and who sees which name; notification categories and the extra events; the scheduled jobs that exist | Founder |
| 2026-10-14 | 17.1 | Recorded as built, no new behaviour: the reputation recompute has no schedule; it runs as a queued job on project close and review publish | Founder |
| 2026-10-15 | 10.4, 17.1 | SCR-035: a staging copy (`APP_ENV=staging`) is never indexed: robots disallow all, sitemap 404, `noindex` header on every response | Founder |
| 2026-10-15 | 6.10, 8, 12, 19 | SCR-036: one shared placeholder marker on every seeded agreement template; a placeholder counts as not published and cannot be accepted or used; the admin screen labels it | Founder |
| 2026-10-15 | 17.4 | SCR-037: the deploy script writes a pre-deploy dump (own file name and retention, size check) before the site goes into maintenance mode, and stops if it fails | Founder |
| 2026-10-15 | 17.1, 17.2 | SCR-038: `public/.htaccess` redirects plain HTTP to HTTPS and honours `X-Forwarded-Proto` | Founder |
| 2026-10-21 | 15.1 | Recorded as built, decided in Pass 9 item 2 (SUGGESTIONS 247, 293): an existing email is skipped, never updated; `status` accepts only `pending`; a client row needs a contact name and email; a contact already in another organization is refused; file limit 5 MB and 5,000 rows; invites go out at `groibe.invites.per_minute` (30) | Founder |
| 2026-10-21 | 7.10 | Recorded as built (SUGGESTIONS 293): plain, non-unique index on `organizations.gstin` | Founder |
| 2026-10-21 | 17.2 | Recorded as built (SUGGESTIONS 271, 272): data request screens at `/settings/privacy` and System > Data requests; the export is made on the spot, never stored; what a copy holds and what erasure removes and keeps; erasure refused while work or money is open; the person sees the admin's note | Founder |
| 2026-10-21 | 17.2 | Recorded as built (SUGGESTIONS 312): while a super admin impersonates, every audit entry carries `impersonated_by`, and accepting a SOW, the Client MSA or the Member Agreement, accepting or changing a milestone, showcase consent and opening a data request are refused | Founder |
| 2026-10-21 | 5, 13 | Recorded as built (SUGGESTIONS 311): an admin role creates only the roles its permission row allows; email, password, status and role of an existing account are super admin only | Founder |
| 2026-10-21 | 6.11, 12 | Recorded as built (SUGGESTIONS 289, 290, 310): client registration creates the account, a `pending` organization and the owner link; a registered GSTIN is refused; the client Organization page lets owner and manager edit and the owner invite a manager or viewer; changing or removing a member stays an admin task | Founder |
| 2026-10-21 | 17.1, 17.4 | SCR-039: retention settings (failed jobs and job batches 30 days, read notifications 180, audit log 365) with daily prune jobs; a daily reminder to super admins of data requests waiting over 7 days; a System status alert when the cron heartbeat is over 10 minutes old; test agreement wording cannot be published on production and is flagged if present | Founder |
