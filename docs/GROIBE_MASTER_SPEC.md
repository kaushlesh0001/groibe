# GROIBE MASTER SPEC v1.0

Status: FROZEN for build. Changes only between build passes, logged in section 23.
Audience: Claude Code (Sonnet 5.5) and any human developer.
Rule: read this whole document before generating any code.

---

## 1. Purpose and positioning

- Groibe is a community-powered professional ecosystem and project delivery platform.
- Target members: skilled Indian professionals. Target clients: mid-market businesses.
- Strategy: "Collective Delivery". Clients buy outcomes from Groibe. Groibe assembles and manages a team of contributors.
- Tagline: "Ideas find people. Work gets done."
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
| Payout | - | R own | - | R | - | CRUD | all |
| Agreements | - | accept | accept | R | R | R | CRUD |
| Website CMS | - | - | - | - | CRUD | - | all |
| Settings, flags | - | - | - | - | - | - | CRUD |
| Audit log | - | - | - | R own actions | R own actions | R own | R all |
| Import/Export | - | - | - | contributors+projects | contributors | payouts | all |

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
- Member Agreement acceptance required before `active`.

### 6.11 Client onboarding
- Self-registration: `registered -> verified_email -> org_pending -> org_verified`.
- Admin-created clients: Admin creates Organization + owner user, sends invite email with signed activation link. Client sets password and accepts Client MSA.
- Client cannot submit a Brief until Org is `org_verified` OR Admin sets `can_submit_briefs`.

## 7. Data model

Conventions:
- Engine InnoDB, utf8mb4. Bigint auto-increment `id`, plus `uuid` (char 26 ULID) on public-facing models. Soft deletes on users, organizations, projects, briefs.
- Timestamps on every table. Foreign keys with explicit `onDelete` behavior. Index every FK and every status column.
- Money: `decimal(14,2)` plus `currency` char(3) default `INR`.
- Enums stored as strings, cast to PHP Enums.
- JSON columns only for flexible metadata, never for queryable core fields.

### 7.1 Identity and organizations

**users**: id, uuid, name, email (unique), password, role (`admin|contributor|client`), status (`active|pending|suspended`), email_verified_at, last_login_at, timezone, remember_token, deleted_at.

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
**applications**: id, open_role_id, user_id, status, motivation, relevant_experience, availability_note, expected_amount (nullable), proof_of_work_ids (json of ids), admin_note, shortlisted_at, decided_at, decided_by. Unique (open_role_id, user_id).

### 7.5 Proof, reviews, reputation

**proof_of_work**: id, uuid, user_id, project_id (nullable), title, role_title, summary, contribution_text, skills_demonstrated (via pivot), outcome_metrics (json), visibility (`private|members|public`), status (`draft|pending_approval|published|hidden`), client_display (`anonymous|named`), approved_by, published_at.
**proof_of_work_skill**: proof_of_work_id, skill_id.
**reviews**: id, project_id, reviewer_id, reviewee_type (`user|organization`), reviewee_id, direction (`client_to_contributor|contributor_to_client|admin_to_contributor`), quality, communication, reliability, timeliness (1-5 each, nullable for org direction use the org criteria fields), clarity, professionalism, feedback_time, fairness, comment, status (`pending_moderation|published|hidden`), published_at. Unique (project_id, reviewer_id, reviewee_type, reviewee_id).
**reputation_facts** (per contributor, recomputed by job): user_id, projects_completed, tasks_approved_first_pass, tasks_total, on_time_tasks, reviews_count, avg_rating, verified_skills_count, recomputed_at. Org equivalent in **organization_stats**.

### 7.6 Money tracking (references only)

**payouts**: id, assignment_id, user_id, project_id, amount, currency, status (`planned|approved|paid|cancelled`), milestone_id (nullable), reference (UTR / txn ref), tds_amount (nullable), paid_at, notes, created_by.
**invoice_refs**: id, project_id, zoho_invoice_ref, amount, status (`draft|sent|paid|overdue`), issued_at, due_at.

### 7.7 Agreements

**agreement_templates**: id, type (`member_agreement|client_msa|sow_template|privacy|terms`), version, title, body_html, is_active, effective_from.
**agreement_acceptances**: id, template_id, user_id, organization_id (nullable), project_id (nullable), accepted_at, ip, user_agent.

### 7.8 Communication

**threads**: id, project_id (nullable), type (`project|support|application`), subject, created_by. **thread_participants**: thread_id, user_id, last_read_at. **messages**: id, thread_id, user_id, body, is_internal (visible to admin only), created_at. Message files: media library, private.
**notifications**: use Laravel's database notifications table, plus an `email_log` table (user_id, template, to, status, error, sent_at).

### 7.9 Website CMS and ops

**cms_sections**: id, key (e.g. `hero`, `how_it_works`), title, content (json), sort, is_active.
**featured_items**: id, type (`open_role|contributor|organization|proof_of_work|testimonial|project`), item_id, sort, is_active.
**testimonials**: id, author_name, author_title, organization_name, quote, photo_path, is_active, sort.
**faqs**: id, audience (`general|contributor|client`), question, answer, sort, is_active.
**pages**: id, slug, title, body_html, meta_title, meta_description, is_active.
**settings**: key/value (typed) store with cache.
**feature_flags**: key, enabled, notes. Keys listed in section 18.
**activity_log**: from spatie/laravel-activitylog (admin audit trail; includes impersonation events).
**imports**: id, type, file_path, status, total_rows, success_rows, failed_rows, error_report_path, created_by.

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

1. Hero: "Ideas find people. Work gets done." + two CTAs ("Explore Opportunities", "Join Groibe") and a secondary line for clients ("Need work delivered? Submit a brief").
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
- Contact form stores to DB and emails admin. Spam protection: honeypot + throttle.

### 10.3 Brand and design

- Colors (Tailwind tokens): navy `#001844` (primary text and dark surfaces), green `#14C352` (primary action and success), blue `#137AE5` (links and secondary accent).
- Tone: modern, calm, professional. Lots of whitespace, strong typography, subtle borders, minimal cards, no gradient overload, no stock-photo look.
- Typeface: one sans family via self-hosted font files (no third-party font requests).
- Mobile-first. Dark mode not required in v1.
- Accessibility: WCAG AA contrast, keyboard navigation, labels on all inputs.
- Copy rules: no em dashes, no AI-related language in public copy.

### 10.4 SEO

- Unique title and meta description per page, canonical URLs, Open Graph tags, JSON-LD (`Organization`, `JobPosting`-style data only if applicable, `BreadcrumbList`).
- Private profiles and organizations are `noindex` and not in sitemap.

## 11. Contributor app (`/app/contributor`)

Navigation: Dashboard, Discover (open roles), My Applications, My Work (assignments, tasks), Portfolio (proof of work), Reputation, Messages, Notifications, Profile, Settings.

- **Registration**: step 1 name/email/password + interests; step 2 profile; step 3 skills (with level) and availability; step 4 accept Member Agreement; step 5 submitted for review.
- **Dashboard**: onboarding status, active assignments, tasks due, applications status, recommended open roles (rule-based: skill overlap + availability), reputation facts.
- **Discover**: filters (skill, type, work mode, compensation type, duration), search, role page, apply form.
- **Apply form**: motivation, relevant experience, availability note, expected amount (optional), select proof-of-work items.
- **My Work**: project list, task board (todo/in progress/submitted/approved), task detail with comments and submission upload (files + note), version history.
- **Portfolio**: proof-of-work drafts and published items, edit text, visibility toggle.
- **Reputation**: facts and received reviews, plus "Review client" prompts after close.
- **Payouts view**: read-only list of own payouts and statuses.

## 12. Client app (`/app/client`)

Navigation: Dashboard, Briefs, Projects, Deliverables, Messages, Notifications, Organization, Settings.

- **Registration**: account, organization details. The organization owner accepts the Client MSA on its own page (`/app/client/agreements/client-msa`) before the first brief or SOW acceptance (spec 8).
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
- **Delivery**: Briefs, Projects (with relation managers: SOWs, Milestones, Tasks, Assignments, Payouts, Invoices, Threads), QA Queue (submissions awaiting review), Deliverables.
- **Community**: Open Roles, Applications, Proof of Work, Reviews (moderation).
- **Website**: Homepage Sections, Featured Items, Testimonials, FAQs, Pages, Contact Messages.
- **Taxonomy**: Categories, Skills, Project Types.
- **Finance**: Payouts, Invoice References (exports).
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
- Contributors cannot start threads with clients. Support thread (user <-> admin) available to everyone.
- Notifications (database + email, user-configurable per category except security):
  - Brief status changes, SOW sent/accepted, assignment invite, application status, task assigned/changed, submission QA result, deliverable shared, deliverable accepted/changes requested, review requested, new message, payout status change, onboarding status change.
- Bell icon with unread count, notifications page, mark read.
- Email via Laravel Mail with markdown templates; templates editable only in code in v1 (listed in `resources/views/emails`). Use a `NotificationDispatcher` service so providers can change later.
- Emails sent through the queue (database driver). Failures logged to `email_log`.

## 15. Import and export

### 15.1 Import (Admin, Filament import actions)

Flow: upload CSV/XLSX -> map columns -> dry-run validation preview -> import -> downloadable error report. Imports run queued in chunks of 200 rows. Idempotent by email (users) and by name+website or gstin (organizations).

**Contributors CSV**: name, email, phone, city, state, headline, bio, skills (semicolon-separated names), experience_years, linkedin_url, availability, status (default `pending`).
**Clients CSV**: organization_name, website, industry, size_band, city, state, gstin, contact_name, contact_email, contact_phone, designation, org_role (default `owner` for the first contact, `manager` for others), zoho_crm_id (optional).
- Rows with the same organization_name+website group under one Organization, with multiple users.
- Unknown skills: create as inactive skills and report them; admin reviews.
- Imported users get `status=pending`, no password. Admin triggers "Send invites" (bulk, throttled, queued).
- Invite link: signed URL, expires in 7 days, resendable.

### 15.2 Export

CSV export for: contributors, organizations, clients, briefs, projects, applications, reviews, payouts, invoice refs. Respects permission matrix. Exports run synchronously up to 5,000 rows, else queued with email link.

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
- Scheduled jobs: reputation recompute, stale-invite cleanup, sitemap refresh, daily DB-size check, notification digests.
- Assets built locally with Vite and committed or uploaded (`public/build`). The server never runs npm.
- Do not rely on `storage:link` symlinks working. Public media goes to a dedicated public disk under `public/` or is served through a controller. Private files served only via authorized controller routes.
- Keep memory use modest (<256MB per request). Paginate everything. Avoid N+1 (use eager loading; add `Model::preventLazyLoading()` in non-production).

### 17.2 Security

- Laravel auth, bcrypt/argon hashing, CSRF, validated Form Requests everywhere, Policies on every model.
- Rate limiting: login (5/min per IP+email), register, contact form, password reset, invite redemption.
- Email verification required. Password rules: min 10 chars, not compromised-checked if available.
- File uploads: allowlist extensions and MIME, size caps (profile photo 2 MB, logo 2 MB, portfolio image 5 MB, documents 20 MB, submissions 25 MB), randomized storage names, no executable types, images re-encoded where possible.
- Private files: `private` disk only; access via signed, authorized routes; never guessable paths.
- Security headers via middleware (HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, a conservative CSP).
- Admin: optional TOTP 2FA for admin roles (Filament-compatible package or custom), enforced for `super_admin`.
- Sensitive data: do not collect government IDs, bank accounts, or card numbers in v1. Payout `reference` stores only a transaction reference.
- Audit: activity log on all admin writes, role changes, impersonation, agreement acceptances, exports.
- DPDP-oriented privacy: collect minimum data, state purpose, allow user data export and deletion request (admin-processed), consent timestamps stored.

### 17.3 Performance

- Page response target: <500ms server time on public pages with warm cache.
- Cache public lists (open roles, featured items, stats) for 5-15 minutes; bust on change.
- Use Cloudflare in front; set cache headers for static assets.

### 17.4 Observability and ops

- Log channel `daily`, retention 14 days. Exceptions emailed to admin address (throttled).
- Health route `/up` (Laravel default) and an admin "System status" widget (queue backlog, failed jobs, last cron run).
- Backups: host daily backups plus a weekly DB dump command (`php artisan groibe:backup-db`) written outside web root.

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
- Agreement templates (placeholders), FAQs (10 general, 6 contributor, 6 client), homepage CMS sections with default copy, feature flags.
- Demo dataset (local/staging only, `DemoSeeder`): 30 contributors, 12 organizations, 8 briefs, 6 projects across all states, 10 open roles, applications, reviews, proof-of-work items. Must never run in production.

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
- Applications cannot be edited after submission (withdraw and reapply).
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
