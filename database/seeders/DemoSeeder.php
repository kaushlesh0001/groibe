<?php

namespace Database\Seeders;

use App\Actions\Agreements\AcceptClientMsa;
use App\Actions\Contributors\AcceptMemberAgreement;
use App\Enums\AssignmentStatus;
use App\Enums\BriefStatus;
use App\Enums\Confidentiality;
use App\Enums\MilestoneStatus;
use App\Enums\OnboardingState;
use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\SowStatus;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Assignment;
use App\Models\Brief;
use App\Models\Milestone;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Skill;
use App\Models\Sow;
use App\Models\Task;
use App\Models\User;
use App\Services\Projects\ProjectCodeGenerator;
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
        ]);

        activity()->withoutLogs(fn () => Model::unguarded(function (): void {
            $this->contributors();
            $organizations = $this->organizations();
            $this->deliveryWork($organizations);
        }));
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
