<?php

namespace Database\Seeders;

use App\Actions\Agreements\AcceptClientMsa;
use App\Actions\Contributors\AcceptMemberAgreement;
use App\Enums\AgreementType;
use App\Enums\ApplicationStatus;
use App\Enums\AssignmentStatus;
use App\Enums\BriefStatus;
use App\Enums\ClientDisplay;
use App\Enums\CompensationType;
use App\Enums\Confidentiality;
use App\Enums\FeaturedItemType;
use App\Enums\MilestoneStatus;
use App\Enums\OnboardingState;
use App\Enums\OpenRoleStatus;
use App\Enums\OpenRoleVisibility;
use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\ProofStatus;
use App\Enums\ProofVisibility;
use App\Enums\ReviewDirection;
use App\Enums\ReviewStatus;
use App\Enums\ShowcaseConsent;
use App\Enums\SowStatus;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WorkMode;
use App\Models\AgreementTemplate;
use App\Models\Application;
use App\Models\Assignment;
use App\Models\Brief;
use App\Models\FeaturedItem;
use App\Models\Milestone;
use App\Models\OpenRole;
use App\Models\Organization;
use App\Models\OrganizationStat;
use App\Models\Profile;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\ProofOfWork;
use App\Models\ReputationFact;
use App\Models\Review;
use App\Models\Skill;
use App\Models\Sow;
use App\Models\Task;
use App\Models\Testimonial;
use App\Models\User;
use App\Services\Projects\ProjectCodeGenerator;
use App\Services\Reputation\ReputationCalculator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fake demo data for local and staging only (spec 19). Additive and safe to run twice: every record is keyed on
 * a stable value (email, slug, title) and left alone when it already exists. Nothing here sends mail or
 * notifications. Run with: php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    /** Shared by every demo login. Fake accounts on a reserved domain, so this is not a secret. */
    public const PASSWORD = 'DemoPass#2026';

    public const EMAIL_DOMAIN = 'demo.groibe.test';

    /**
     * name, headline, city, state, onboarding state, public profile, skills (name => level).
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: OnboardingState, 5: bool, 6: array<string, string>}>
     */
    private function contributorData(): array
    {
        return [
            ['Aditi Sharma', 'Market research analyst', 'Kolkata', 'West Bengal', OnboardingState::Active, true, ['Market Research' => 'expert', 'Survey Design' => 'advanced', 'Competitor Analysis' => 'advanced']],
            ['Rohan Mehta', 'Full-stack developer', 'Pune', 'Maharashtra', OnboardingState::Active, true, ['Laravel' => 'expert', 'React' => 'advanced', 'API Integration' => 'advanced']],
            ['Sneha Iyer', 'UX designer', 'Bengaluru', 'Karnataka', OnboardingState::Active, true, ['UI Design' => 'expert', 'UX Research' => 'advanced', 'Presentation Design' => 'intermediate']],
            ['Vikram Singh', 'Financial modelling consultant', 'Mumbai', 'Maharashtra', OnboardingState::Active, true, ['Financial Modelling' => 'expert', 'Excel Modelling' => 'expert', 'Budgeting and Forecasting' => 'advanced']],
            ['Priya Nair', 'Content strategist', 'Kochi', 'Kerala', OnboardingState::Active, false, ['Copywriting' => 'expert', 'Content Strategy' => 'advanced', 'Editing and Proofreading' => 'advanced']],
            ['Arjun Das', 'Data analyst', 'Kolkata', 'West Bengal', OnboardingState::Active, false, ['SQL' => 'advanced', 'Power BI' => 'expert', 'Python for Data' => 'intermediate']],
            ['Meera Kulkarni', 'Operations consultant', 'Pune', 'Maharashtra', OnboardingState::Active, false, ['Process Mapping' => 'expert', 'Project Management' => 'advanced', 'Zoho One Setup' => 'intermediate']],
            ['Kabir Joshi', 'SEO specialist', 'Delhi', 'Delhi', OnboardingState::PendingReview, false, ['SEO' => 'advanced', 'Performance Marketing' => 'intermediate']],
            ['Ananya Bose', 'Brand designer', 'Kolkata', 'West Bengal', OnboardingState::PendingReview, false, ['Graphic Design' => 'advanced', 'Brand Identity' => 'advanced']],
            ['Dev Malhotra', 'Sales operations lead', 'Gurugram', 'Haryana', OnboardingState::PendingReview, false, ['CRM Administration' => 'advanced', 'Lead Generation' => 'intermediate']],
            ['Ishita Rao', 'Technical writer', 'Hyderabad', 'Telangana', OnboardingState::Suspended, false, ['Technical Writing' => 'advanced', 'Editing and Proofreading' => 'intermediate']],
            ['Nikhil Verma', 'QA tester', 'Noida', 'Uttar Pradesh', OnboardingState::Suspended, false, ['QA Testing' => 'advanced', 'API Integration' => 'beginner']],
        ];
    }

    /**
     * name, slug, industry, city, state, status, public, MSA accepted, users (name => org role).
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: OrganizationStatus, 6: bool, 7: bool, 8: array<string, OrgRole>}>
     */
    private function organizationData(): array
    {
        return [
            ['Kestrel Foods (Demo)', 'kestrel-foods-demo', 'Manufacturing', 'Kolkata', 'West Bengal', OrganizationStatus::Verified, true, true, ['Rhea Kapoor' => OrgRole::Owner, 'Sameer Ghosh' => OrgRole::Manager]],
            ['Bluepeak Logistics (Demo)', 'bluepeak-logistics-demo', 'Logistics', 'Mumbai', 'Maharashtra', OrganizationStatus::Verified, false, true, ['Tanvi Desai' => OrgRole::Owner, 'Harsh Vora' => OrgRole::Manager, 'Leela Menon' => OrgRole::Viewer]],
            ['Northwind Health (Demo)', 'northwind-health-demo', 'Healthcare', 'Pune', 'Maharashtra', OrganizationStatus::Verified, false, true, ['Dr Farhan Qureshi' => OrgRole::Owner]],
            ['Lumen Retail (Demo)', 'lumen-retail-demo', 'Retail and E-commerce', 'Delhi', 'Delhi', OrganizationStatus::Pending, false, false, ['Nisha Arora' => OrgRole::Owner]],
        ];
    }

    public function run(): void
    {
        if (! app()->environment(['local', 'staging', 'testing'])) {
            throw new RuntimeException('DemoSeeder runs only in local or staging. Refusing to run in "'.app()->environment().'".');
        }

        // Everything the demo data leans on. All of these are idempotent.
        $this->call([
            RolesAndPermissionsSeeder::class,
            TaxonomySeeder::class,
            FeatureFlagSeeder::class,
            AgreementTemplateSeeder::class,
            WebsiteContentSeeder::class,
        ]);

        activity()->withoutLogs(fn () => Model::unguarded(function (): void {
            $this->publishDemoWording();
            $this->contributors();
            $organizations = $this->organizations();
            $this->deliveryWork($organizations);
            $this->showcaseWork($organizations);
            $this->openRoles($organizations);
            // Last, because it picks the roles, people and work the steps above create.
            $this->homepage($organizations);
        }));
    }

    /**
     * The demo people accept the Member Agreement and Client MSA, and the demo projects use the SOW template, but a template that
     * still carries the placeholder marker counts as not published and cannot be accepted (spec 8, SCR-036). So each of those
     * three gets a clearly labelled demo version, only while its current version is still a placeholder. Real wording is left alone.
     */
    private function publishDemoWording(): void
    {
        foreach ([AgreementType::MemberAgreement, AgreementType::ClientMsa, AgreementType::SowTemplate] as $type) {
            $current = AgreementTemplate::currentFor($type);

            if ($current === null || ! $current->isPlaceholder()) {
                continue;
            }

            AgreementTemplate::query()->where('type', $type)->update(['is_active' => false]);

            AgreementTemplate::query()->create([
                'type' => $type,
                'version' => (int) AgreementTemplate::query()->where('type', $type)->max('version') + 1,
                'title' => $type->getLabel().' (demo wording)',
                'body_html' => str_replace(AgreementTemplate::PLACEHOLDER_NOTE, 'Demo wording for local and staging only. This is not legal text.', (string) $current->body_html),
                'is_active' => true,
                'effective_from' => now(),
            ]);
        }
    }

    private function contributors(): void
    {
        foreach ($this->contributorData() as [$name, $headline, $city, $state, $onboarding, $public, $skills]) {
            $user = $this->user($name, UserRole::Contributor, match ($onboarding) {
                OnboardingState::Active => UserStatus::Active,
                OnboardingState::Suspended => UserStatus::Suspended,
                default => UserStatus::Pending,
            });

            Profile::query()->firstOrCreate(['user_id' => $user->id], [
                'headline' => $headline,
                'bio' => "Demo profile for {$name}. This is fake data for testing.",
                'city' => $city,
                'state' => $state,
                'country' => 'India',
                'availability' => 'available',
                'hours_per_week' => 15,
                'work_modes' => ['remote'],
                'experience_years' => 6,
                'languages' => ['English', 'Hindi'],
                'is_public' => $public,
                'slug' => $public ? Str::slug($name).'-demo' : null,
                'onboarding_state' => $onboarding,
                'completed_at' => now(),
            ]);

            // Every state past registration has gone through step 4 of onboarding.
            app(AcceptMemberAgreement::class)($user);

            foreach ($skills as $skillName => $level) {
                $skill = Skill::query()->where('name', $skillName)->first();

                if ($skill !== null && ! $user->skills()->whereKey($skill->id)->exists()) {
                    $user->skills()->attach($skill->id, [
                        'level' => $level,
                        'years' => 4,
                        'is_verified' => $onboarding === OnboardingState::Active && $public,
                        'verified_at' => $onboarding === OnboardingState::Active && $public ? now() : null,
                    ]);
                }
            }
        }
    }

    /** @return array<string, Organization> keyed by slug */
    private function organizations(): array
    {
        $organizations = [];

        foreach ($this->organizationData() as [$name, $slug, $industry, $city, $state, $status, $public, $msa, $users]) {
            $organization = Organization::query()->firstOrCreate(['slug' => $slug], [
                'name' => $name,
                'legal_name' => str_replace(' (Demo)', ' Private Limited', $name),
                'industry' => $industry,
                'size_band' => '51-200',
                'description' => 'Fictional client organization for demo and testing.',
                'city' => $city,
                'state' => $state,
                'country' => 'India',
                'status' => $status,
                'verified_at' => $status === OrganizationStatus::Verified ? now() : null,
                'can_submit_briefs' => false,
                'is_public' => $public,
                'is_featured' => $public,
            ]);

            foreach ($users as $userName => $orgRole) {
                $user = $this->user($userName, UserRole::Client, UserStatus::Active);

                if (! $organization->users()->whereKey($user->id)->exists()) {
                    $organization->users()->attach($user->id, ['org_role' => $orgRole->value, 'designation' => $orgRole === OrgRole::Owner ? 'Founder' : 'Manager']);
                }

                if ($msa && $orgRole === OrgRole::Owner) {
                    app(AcceptClientMsa::class)($user, $organization);
                }
            }

            $organizations[$slug] = $organization;
        }

        return $organizations;
    }

    /** @param  array<string, Organization>  $organizations */
    private function deliveryWork(array $organizations): void
    {
        $kestrel = $organizations['kestrel-foods-demo'];
        $bluepeak = $organizations['bluepeak-logistics-demo'];
        $northwind = $organizations['northwind-health-demo'];

        $admin = User::query()->where('role', UserRole::Admin)->orderBy('id')->first();

        $this->brief($kestrel, 'Distributor survey and market sizing', BriefStatus::Submitted, ['Market Research', 'Survey Design'], 'Market Research');
        $this->brief($bluepeak, 'Route cost model', BriefStatus::Scoped, ['Financial Modelling', 'Excel Modelling'], 'Financial Modelling');
        $this->brief($northwind, 'Patient feedback analysis', BriefStatus::NeedsInfo, ['Data Cleaning', 'Power BI'], 'Dashboard/Reporting', 'Which sites and how many responses a month?');
        $this->brief($northwind, 'Website refresh', BriefStatus::Draft, ['WordPress'], 'Website/App Build');

        // 1. Active: accepted SOW, one active assignment, a milestone in progress.
        $dashboardBrief = $this->brief($kestrel, 'Sales dashboard in Power BI', BriefStatus::Converted, ['Power BI', 'SQL'], 'Dashboard/Reporting');
        $dashboard = $this->project($kestrel, 'Sales dashboard in Power BI', ProjectStatus::Active, 180000, $admin, $dashboardBrief);
        $this->sow($dashboard, SowStatus::Accepted, $kestrel);
        $arjun = $this->contributor('Arjun Das');
        $assignment = Assignment::query()->firstOrCreate(['project_id' => $dashboard->id, 'user_id' => $arjun->id], [
            'role_title' => 'BI developer', 'status' => AssignmentStatus::Active, 'rate_type' => 'fixed',
            'agreed_amount' => 90000, 'currency' => 'INR', 'invited_at' => now()->subWeeks(2), 'accepted_at' => now()->subWeeks(2),
        ]);
        $build = Milestone::query()->firstOrCreate(['project_id' => $dashboard->id, 'title' => 'Data model and first dashboard'], [
            'description' => 'Clean the sales extract and publish the first report.', 'sort' => 1,
            'status' => MilestoneStatus::InProgress, 'value' => 90000, 'due_date' => now()->addWeeks(2),
        ]);
        Milestone::query()->firstOrCreate(['project_id' => $dashboard->id, 'title' => 'Handover and training'], [
            'description' => 'Walk the team through the dashboard.', 'sort' => 2, 'status' => MilestoneStatus::Planned, 'value' => 90000, 'due_date' => now()->addWeeks(5),
        ]);
        $this->task($build, 'Clean the sales extract', TaskStatus::Approved, $assignment);
        $this->task($build, 'Build the regional sales report', TaskStatus::InProgress, $assignment);
        $this->task($build, 'Add the distributor drill-down', TaskStatus::Todo, null);

        // 2. SOW sent to the client, waiting for acceptance.
        $fleetBrief = $this->brief($bluepeak, 'Fleet reporting automation', BriefStatus::Converted, ['Process Mapping', 'Power BI'], 'Process Automation');
        $fleet = $this->project($bluepeak, 'Fleet reporting automation', ProjectStatus::SowSent, 240000, $admin, $fleetBrief);
        $this->sow($fleet, SowStatus::Sent, $bluepeak);

        // 3. Staffing: SOW accepted, one contributor invited.
        $content = $this->project($northwind, 'Clinic onboarding content pack', ProjectStatus::Staffing, 120000, $admin);
        $this->sow($content, SowStatus::Accepted, $northwind);
        Assignment::query()->firstOrCreate(['project_id' => $content->id, 'user_id' => $this->contributor('Priya Nair')->id], [
            'role_title' => 'Content lead', 'status' => AssignmentStatus::Invited, 'rate_type' => 'fixed',
            'agreed_amount' => 60000, 'currency' => 'INR', 'invited_at' => now()->subDays(2),
        ]);

        // 4. Draft: internal only, no SOW yet.
        $this->project($kestrel, 'Distributor onboarding playbook', ProjectStatus::Draft, 90000, $admin);
    }

    /**
     * Pass 6 showcase data: three accepted projects (one per showcase consent value) and one closed project with
     * proof of work and reviews. Rows are written directly, so nothing is notified or queued, and reputation is
     * computed by calling the calculator once for anyone who has no cached row yet.
     *
     * @param  array<string, Organization>  $organizations
     */
    private function showcaseWork(array $organizations): void
    {
        $kestrel = $organizations['kestrel-foods-demo'];
        $bluepeak = $organizations['bluepeak-logistics-demo'];
        $northwind = $organizations['northwind-health-demo'];
        $admin = User::query()->where('role', UserRole::Admin)->orderBy('id')->first();

        // Accepted, one per consent value. Only `anonymous` and `named` can ever reach the public site.
        $this->finishedProject($bluepeak, 'Warehouse KPI dashboard', ProjectStatus::Accepted, ShowcaseConsent::None, 150000, $admin, 'Dashboard/Reporting', [
            'Rohan Mehta' => 'API developer',
        ]);
        $survey = $this->finishedProject($northwind, 'Patient survey report', ProjectStatus::Accepted, ShowcaseConsent::Anonymous, 110000, $admin, 'Market Research', [
            'Aditi Sharma' => 'Research analyst',
        ]);
        $this->finishedProject($kestrel, 'Pricing page redesign', ProjectStatus::Accepted, ShowcaseConsent::Named, 130000, $admin, 'Website/App Build', [
            'Sneha Iyer' => 'UX designer',
        ]);

        // Closed and named: the only project with proof of work, because drafts are created when a project closes.
        $model = $this->finishedProject($kestrel, 'Distributor pricing model', ProjectStatus::Closed, ShowcaseConsent::Named, 220000, $admin, 'Financial Modelling', [
            'Vikram Singh' => 'Financial modeller',
            'Aditi Sharma' => 'Survey analyst',
            'Sneha Iyer' => 'Presentation designer',
        ]);

        $this->proof($model, 'Vikram Singh', 'demo-distributor-pricing-model-vikram', ProofStatus::Published, ProofVisibility::Public, [
            'summary' => 'Built the pricing model that sets distributor margins by region.',
            'contribution_text' => 'Rebuilt the margin logic from the raw price lists, tested it against a year of orders and handed over a model the finance team now updates themselves.',
            'outcome_metrics' => [['label' => 'Regions modelled', 'value' => '6'], ['label' => 'Price lists reconciled', 'value' => '14']],
            'skills' => ['Financial Modelling', 'Excel Modelling'],
        ], $admin);
        $this->proof($model, 'Aditi Sharma', 'demo-distributor-survey-aditi', ProofStatus::Published, ProofVisibility::Members, [
            'summary' => 'Ran the distributor survey that fed the pricing model.',
            'contribution_text' => 'Wrote the questionnaire, collected responses from distributors and summarised what they said about pricing.',
            'outcome_metrics' => [['label' => 'Responses', 'value' => '48']],
            'skills' => ['Survey Design', 'Market Research'],
        ], $admin);
        $this->proof($model, 'Sneha Iyer', null, ProofStatus::Draft, ProofVisibility::Private, [
            'skills' => ['Presentation Design'],
        ], $admin);

        $this->review($model, 'Rhea Kapoor', ReviewDirection::ClientToContributor, 'Vikram Singh', ReviewStatus::Published, 5, 'Clear, careful and on time. The model answered the questions we actually had.');
        $this->review($model, 'Rhea Kapoor', ReviewDirection::ClientToContributor, 'Aditi Sharma', ReviewStatus::Published, 4, 'Good survey design and a readable summary.');
        $this->review($model, 'Rhea Kapoor', ReviewDirection::ClientToContributor, 'Sneha Iyer', ReviewStatus::PendingModeration, 4, 'Clean slides. Waiting in the moderation queue.');
        $this->review($survey, 'Dr Farhan Qureshi', ReviewDirection::ClientToContributor, 'Aditi Sharma', ReviewStatus::Published, 5, 'Thorough work, delivered ahead of the date.');
        // Organization-directed, so admin-only while `public_client_reviews` is off.
        $this->review($model, 'Vikram Singh', ReviewDirection::ContributorToClient, $kestrel, ReviewStatus::Published, 4, 'Clear brief and quick answers to questions.');

        $calculator = app(ReputationCalculator::class);

        foreach (['Vikram Singh', 'Aditi Sharma', 'Sneha Iyer', 'Rohan Mehta'] as $name) {
            $user = $this->contributor($name);

            if (! ReputationFact::query()->where('user_id', $user->id)->exists()) {
                $calculator->forUser($user);
            }
        }

        if (! OrganizationStat::query()->where('organization_id', $kestrel->id)->exists()) {
            $calculator->forOrganization($kestrel);
        }
    }

    /**
     * A project that has already been delivered, with one finished milestone and an approved task per seat so
     * reputation has something to count. Keyed on organization and title, so a second run leaves it alone.
     *
     * @param  array<string, string>  $seats  contributor name => role title
     */
    private function finishedProject(Organization $organization, string $title, ProjectStatus $status, ShowcaseConsent $consent, int $value, ?User $admin, string $projectType, array $seats): Project
    {
        $existing = Project::query()->where('organization_id', $organization->id)->where('title', $title)->first();

        if ($existing !== null) {
            return $existing;
        }

        $project = $this->project($organization, $title, $status, $value, $admin);
        $project->forceFill([
            'project_type_id' => ProjectType::query()->where('name', $projectType)->value('id'),
            'showcase_consent' => $consent,
            'start_date' => now()->subWeeks(10),
            'due_date' => now()->subWeeks(2),
            'closed_at' => $status === ProjectStatus::Closed ? now()->subWeek() : null,
        ])->save();

        $this->sow($project, SowStatus::Accepted, $organization);

        $milestone = Milestone::query()->create([
            'project_id' => $project->id, 'title' => 'Delivery', 'description' => 'Deliver the agreed work.', 'sort' => 1,
            'status' => MilestoneStatus::ClientAccepted, 'value' => $value, 'due_date' => now()->subWeeks(3),
        ]);

        foreach ($seats as $name => $roleTitle) {
            $assignment = Assignment::query()->create([
                'project_id' => $project->id, 'user_id' => $this->contributor($name)->id, 'role_title' => $roleTitle,
                'status' => AssignmentStatus::Completed, 'rate_type' => 'fixed', 'agreed_amount' => intdiv($value, count($seats)),
                'currency' => 'INR', 'invited_at' => now()->subWeeks(10), 'accepted_at' => now()->subWeeks(10), 'completed_at' => now()->subWeeks(2),
            ]);

            $this->task($milestone, "Deliver the {$roleTitle} work", TaskStatus::Approved, $assignment);
        }

        return $project;
    }

    /**
     * @param  array{summary?: string, contribution_text?: string, outcome_metrics?: list<array{label: string, value: string}>, skills: list<string>}  $content
     */
    private function proof(Project $project, string $contributorName, ?string $slug, ProofStatus $status, ProofVisibility $visibility, array $content, ?User $admin): void
    {
        $user = $this->contributor($contributorName);
        $assignment = Assignment::query()->where('project_id', $project->id)->where('user_id', $user->id)->firstOrFail();
        $project->loadMissing('projectType');

        $proof = ProofOfWork::query()->firstOrCreate(['user_id' => $user->id, 'project_id' => $project->id], [
            'slug' => $status === ProofStatus::Published ? $slug : null,
            'title' => $assignment->role_title.': '.$project->projectType->name,
            'role_title' => $assignment->role_title,
            'summary' => $content['summary'] ?? null,
            'contribution_text' => $content['contribution_text'] ?? null,
            'outcome_metrics' => $content['outcome_metrics'] ?? [],
            'visibility' => $visibility,
            'status' => $status,
            'client_display' => $project->showcase_consent === ShowcaseConsent::Named ? ClientDisplay::Named : ClientDisplay::Anonymous,
            'approved_by' => $status === ProofStatus::Published ? $admin?->id : null,
            'published_at' => $status === ProofStatus::Published ? now()->subDays(5) : null,
        ]);

        if ($proof->wasRecentlyCreated) {
            $proof->skills()->sync(Skill::query()->whereIn('name', $content['skills'])->pluck('id')->all());
        }
    }

    /** One rating applied to every criterion of the direction. $reviewee is a contributor's name or the organization. */
    private function review(Project $project, string $reviewerName, ReviewDirection $direction, string|Organization $reviewee, ReviewStatus $status, int $rating, string $comment): void
    {
        $reviewer = User::query()->where('email', Str::slug($reviewerName, '.').'@'.self::EMAIL_DOMAIN)->firstOrFail();
        $revieweeId = $reviewee instanceof Organization ? $reviewee->id : $this->contributor($reviewee)->id;

        Review::query()->firstOrCreate([
            'project_id' => $project->id,
            'reviewer_id' => $reviewer->id,
            'reviewee_type' => $direction->revieweeType(),
            'reviewee_id' => $revieweeId,
        ], [
            'direction' => $direction,
            ...array_fill_keys($direction->criteria(), $rating),
            'comment' => $comment,
            'status' => $status,
            'published_at' => $status === ReviewStatus::Published ? now()->subDays(4) : null,
        ]);
    }

    /**
     * What an admin would pick for the homepage: a public role, public contributors, a public organization, a published
     * proof-of-work item, and two clearly fake testimonials (a testimonial must otherwise be a real quote, so none are
     * seeded outside the demo). Keyed on type and item, or on the author, so a second run changes nothing.
     *
     * @param  array<string, Organization>  $organizations
     */
    private function homepage(array $organizations): void
    {
        $picks = [
            [FeaturedItemType::OpenRole, OpenRole::query()->where('slug', 'demo-survey-analyst')->value('id')],
            [FeaturedItemType::Organization, $organizations['kestrel-foods-demo']->id],
            [FeaturedItemType::ProofOfWork, ProofOfWork::query()->where('slug', 'demo-distributor-pricing-model-vikram')->value('id')],
        ];

        foreach (['Aditi Sharma', 'Rohan Mehta', 'Sneha Iyer', 'Vikram Singh'] as $name) {
            $picks[] = [FeaturedItemType::Contributor, $this->contributor($name)->id];
        }

        foreach ($picks as $position => [$type, $id]) {
            if ($id !== null) {
                FeaturedItem::query()->firstOrCreate(['type' => $type, 'item_id' => $id], ['sort' => ($position + 1) * 10, 'is_active' => true]);
            }
        }

        foreach (['Demo Client One', 'Demo Client Two'] as $position => $author) {
            Testimonial::query()->firstOrCreate(['author_name' => $author], [
                'author_title' => 'Founder',
                'organization_name' => 'Kestrel Foods (Demo)',
                'quote' => 'Demo quote for testing the homepage. This is not a real testimonial.',
                'sort' => ($position + 1) * 10,
                'is_active' => true,
                'needs_review' => false,
            ]);
        }
    }

    /** @param  array<string, Organization>  $organizations */
    private function openRoles(array $organizations): void
    {
        $admin = User::query()->where('role', UserRole::Admin)->orderBy('id')->first();
        $dashboard = Project::query()->where('organization_id', $organizations['kestrel-foods-demo']->id)->where('title', 'Sales dashboard in Power BI')->first();

        $survey = $this->role('demo-survey-analyst', 'Survey analyst', OpenRoleVisibility::Public, OpenRoleStatus::Published, ['Survey Design', 'Market Research'], $admin, [
            'summary' => 'Help design and analyse a distributor survey for a manufacturing client.',
            'deadline' => now()->addWeeks(3), 'duration_label' => '4 weeks', 'is_featured' => true, 'positions_needed' => 2,
        ]);
        $support = $this->role('demo-dashboard-support', 'Dashboard support analyst', OpenRoleVisibility::MembersOnly, OpenRoleStatus::Published, ['Power BI', 'SQL'], $admin, [
            'summary' => 'Support the build of a sales dashboard.', 'duration_label' => '6 weeks', 'project_id' => $dashboard?->id,
            'work_mode' => WorkMode::Hybrid, 'compensation_type' => CompensationType::Stipend,
        ]);
        $invite = $this->role('demo-brand-refresh', 'Brand refresh designer', OpenRoleVisibility::InviteOnly, OpenRoleStatus::Published, ['Brand Identity', 'UI Design'], $admin, [
            'summary' => 'A short design engagement for invited contributors.', 'duration_label' => '3 weeks',
        ]);
        $this->role('demo-content-editor', 'Content editor', OpenRoleVisibility::MembersOnly, OpenRoleStatus::Draft, ['Editing and Proofreading'], $admin, [
            'summary' => 'A draft role, not yet visible to anyone but admins.',
        ]);

        $invite->invitedUsers()->syncWithoutDetaching([$this->contributor('Sneha Iyer')->id => ['invited_by' => $admin?->id]]);

        $this->application($survey, 'Aditi Sharma', ApplicationStatus::Submitted);
        $this->application($survey, 'Rohan Mehta', ApplicationStatus::Shortlisted);
        $this->application($support, 'Meera Kulkarni', ApplicationStatus::Offered);
        $this->application($invite, 'Sneha Iyer', ApplicationStatus::Submitted);
    }

    /**
     * @param  list<string>  $skills
     * @param  array<string, mixed>  $extra
     */
    private function role(string $slug, string $title, OpenRoleVisibility $visibility, OpenRoleStatus $status, array $skills, ?User $admin, array $extra = []): OpenRole
    {
        $role = OpenRole::query()->firstOrCreate(['slug' => $slug], [
            'title' => $title,
            'description' => 'Demo role. Fake data for testing.',
            'tasks_list' => ['Work with the team', 'Share findings'],
            'learning_outcomes' => ['Client-ready delivery'],
            'work_mode' => WorkMode::Remote,
            'est_hours_min' => 5,
            'est_hours_max' => 10,
            'compensation_type' => CompensationType::Paid,
            'compensation_note' => 'Fixed fee on delivery',
            'visibility' => $visibility,
            'positions_needed' => 1,
            'status' => $status,
            'published_at' => $status === OpenRoleStatus::Draft ? null : now()->subDays(3),
            'created_by' => $admin?->id,
            ...$extra,
        ]);

        if ($role->wasRecentlyCreated) {
            $role->skills()->sync(Skill::query()->whereIn('name', $skills)->pluck('id')->all());
        }

        return $role;
    }

    private function application(OpenRole $role, string $contributorName, ApplicationStatus $status): void
    {
        Application::query()->firstOrCreate(['open_role_id' => $role->id, 'user_id' => $this->contributor($contributorName)->id], [
            'status' => $status,
            'motivation' => 'Demo application. I would enjoy this work and have done similar projects before.',
            'relevant_experience' => 'Several comparable engagements.',
            'availability_note' => 'Ten hours a week.',
            'shortlisted_at' => in_array($status, [ApplicationStatus::Shortlisted, ApplicationStatus::Offered], true) ? now()->subDay() : null,
        ]);
    }

    private function user(string $name, UserRole $role, UserStatus $status): User
    {
        return User::query()->firstOrCreate(
            ['email' => Str::slug($name, '.').'@'.self::EMAIL_DOMAIN],
            ['name' => $name, 'password' => self::PASSWORD, 'role' => $role, 'status' => $status, 'email_verified_at' => now(), 'timezone' => 'Asia/Kolkata'],
        );
    }

    private function contributor(string $name): User
    {
        return User::query()->where('email', Str::slug($name, '.').'@'.self::EMAIL_DOMAIN)->firstOrFail();
    }

    /** @param  list<string>  $skills */
    private function brief(Organization $organization, string $title, BriefStatus $status, array $skills, string $projectType, ?string $needsInfo = null): Brief
    {
        $brief = Brief::query()->firstOrCreate(['organization_id' => $organization->id, 'title' => $title], [
            'submitted_by' => $organization->users()->wherePivot('org_role', OrgRole::Owner->value)->value('users.id'),
            'problem' => 'Demo brief. The team needs this done and has no capacity in-house.',
            'desired_outcome' => 'A finished, reviewed deliverable the team can use straight away.',
            'project_type_id' => ProjectType::query()->where('name', $projectType)->value('id'),
            'budget_band' => '1l_3l',
            'timeline_band' => '1_3m',
            'confidentiality' => Confidentiality::Standard,
            'status' => $status,
            'needs_info_note' => $status === BriefStatus::NeedsInfo ? $needsInfo : null,
            'submitted_at' => $status === BriefStatus::Draft ? null : now()->subWeeks(3),
        ]);

        if ($brief->wasRecentlyCreated) {
            $brief->skills()->sync(Skill::query()->whereIn('name', $skills)->pluck('id')->all());
        }

        return $brief;
    }

    private function project(Organization $organization, string $title, ProjectStatus $status, int $value, ?User $admin, ?Brief $brief = null): Project
    {
        $existing = Project::query()->where('organization_id', $organization->id)->where('title', $title)->first();

        if ($existing !== null) {
            return $existing;
        }

        $project = DB::transaction(function () use ($organization, $title, $status, $value, $admin, $brief): Project {
            $code = app(ProjectCodeGenerator::class)->next();

            return Project::query()->create([
                'brief_id' => $brief?->id,
                'organization_id' => $organization->id,
                'code' => $code,
                'title' => $title,
                'slug' => Str::slug($code.' '.$title),
                'summary' => 'Demo project for testing.',
                'scope_summary' => 'Fictional scope for a demo engagement.',
                'project_type_id' => $brief?->project_type_id,
                'status' => $status,
                'contract_value' => $value,
                'currency' => 'INR',
                'start_date' => now()->subWeeks(2),
                'due_date' => now()->addWeeks(6),
                'delivery_manager_id' => $admin?->id,
            ]);
        });

        $brief?->forceFill(['converted_project_id' => $project->id])->save();

        return $project;
    }

    private function sow(Project $project, SowStatus $status, Organization $organization): void
    {
        $owner = $organization->users()->wherePivot('org_role', OrgRole::Owner->value)->first();

        Sow::query()->firstOrCreate(['project_id' => $project->id, 'version' => 1], [
            'status' => $status,
            'title' => 'Statement of Work: '.$project->title,
            'body_html' => '<p>Demo statement of work for '.e($project->title).'. Fake data for testing.</p>',
            'body_variables' => [],
            'total_value' => $project->contract_value,
            'currency' => 'INR',
            'payment_terms' => '50% on acceptance, 50% on delivery.',
            'sent_at' => now()->subWeeks(2),
            'accepted_at' => $status === SowStatus::Accepted ? now()->subWeeks(2) : null,
            'accepted_by' => $status === SowStatus::Accepted ? $owner?->id : null,
        ]);
    }

    private function task(Milestone $milestone, string $title, TaskStatus $status, ?Assignment $assignment): void
    {
        Task::query()->firstOrCreate(['milestone_id' => $milestone->id, 'title' => $title], [
            'project_id' => $milestone->project_id,
            'assignment_id' => $assignment?->id,
            'description' => 'Demo task.',
            'estimate_hours' => 8,
            'status' => $status,
            'due_date' => now()->addWeek(),
            'approved_at' => $status === TaskStatus::Approved ? now() : null,
        ]);
    }
}
