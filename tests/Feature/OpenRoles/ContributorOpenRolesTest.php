<?php

use App\Enums\ApplicationStatus;
use App\Enums\AssignmentStatus;
use App\Enums\Availability;
use App\Enums\FeatureFlag;
use App\Enums\OnboardingState;
use App\Enums\OpenRoleStatus;
use App\Enums\ProjectStatus;
use App\Livewire\Contributor\Dashboard;
use App\Livewire\Contributor\Discover\Index;
use App\Livewire\Contributor\Discover\Show;
use App\Livewire\Contributor\MyApplications;
use App\Models\Application;
use App\Models\Assignment;
use App\Models\OpenRole;
use App\Models\Project;
use App\Models\Skill;
use App\Notifications\ApplicationUpdated;
use App\Services\FeatureFlags\FeatureFlags;
use App\Services\OpenRoles\RoleRecommender;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->contributor = contributorIn(OnboardingState::Active);
});

function roleFlag(bool $on): void
{
    app(FeatureFlags::class)->set(FeatureFlag::OpenRoles, $on);
    app(FeatureFlags::class)->flush();
}

describe('route access', function () {
    it('sends guests to log in', function (string $path) {
        $this->get($path)->assertRedirect('/login');
    })->with(['/app/contributor/discover', '/app/contributor/discover/some-role', '/app/contributor/applications']);

    it('keeps clients and admins out', function (string $path) {
        asUser(clientUser())->get($path)->assertForbidden();
        asUser(adminUser())->get($path)->assertForbidden();
    })->with(['/app/contributor/discover', '/app/contributor/discover/some-role', '/app/contributor/applications']);

    it('keeps contributors who are not active out', function (OnboardingState $state, string $path) {
        asUser(contributorIn($state))->get($path)->assertStatus(403);
    })->with([OnboardingState::PendingReview, OnboardingState::Suspended, OnboardingState::ProfileComplete])
        ->with(['/app/contributor/discover', '/app/contributor/applications']);

    it('lets an active contributor in', function () {
        $role = OpenRole::factory()->published()->create();

        asUser($this->contributor)->get('/app/contributor/discover')->assertOk();
        asUser($this->contributor)->get('/app/contributor/discover/'.$role->slug)->assertOk();
        asUser($this->contributor)->get('/app/contributor/applications')->assertOk();
    });

    it('returns 404 when the open_roles flag is off', function () {
        $role = OpenRole::factory()->published()->create();
        roleFlag(false);

        foreach (['/app/contributor/discover', '/app/contributor/discover/'.$role->slug, '/app/contributor/applications'] as $path) {
            asUser($this->contributor)->get($path)->assertNotFound();
        }
    });
});

describe('discover', function () {
    it('shows public, members-only and the invite-only roles they are on the list for', function () {
        $invited = OpenRole::factory()->published()->inviteOnly()->create(['title' => 'Invited role']);
        $invited->invitedUsers()->attach($this->contributor->id);
        OpenRole::factory()->published()->public()->create(['title' => 'Public role']);
        OpenRole::factory()->published()->create(['title' => 'Members role']);
        OpenRole::factory()->published()->inviteOnly()->create(['title' => 'Not mine']);
        OpenRole::factory()->create(['title' => 'Draft role']);
        OpenRole::factory()->status(OpenRoleStatus::Closed)->create(['title' => 'Closed role']);

        Livewire::actingAs($this->contributor)->test(Index::class)
            ->assertSee('Invited role')->assertSee('Public role')->assertSee('Members role')
            ->assertDontSee('Not mine')->assertDontSee('Draft role')->assertDontSee('Closed role');
    });

    it('filters and searches without widening what they can see', function () {
        $skill = Skill::factory()->create(['slug' => 'sql-skill']);
        $match = OpenRole::factory()->published()->create(['title' => 'SQL helper']);
        $match->skills()->attach($skill);
        OpenRole::factory()->published()->create(['title' => 'Other role']);
        $hidden = OpenRole::factory()->published()->inviteOnly()->create(['title' => 'Hidden SQL role']);
        $hidden->skills()->attach($skill);

        Livewire::actingAs($this->contributor)->test(Index::class)
            ->set('skill', 'sql-skill')
            ->assertSee('SQL helper')->assertDontSee('Other role')->assertDontSee('Hidden SQL role')
            ->set('skill', '')->set('q', 'Other')
            ->assertSee('Other role')->assertDontSee('SQL helper');
    });
});

describe('role page and apply', function () {
    it('shows the apply form and sends an application', function () {
        Notification::fake();
        $role = OpenRole::factory()->published()->create(['title' => 'Analyst']);

        Livewire::actingAs($this->contributor)->test(Show::class, ['slug' => $role->slug])
            ->assertSee('Send application')
            ->set('form.motivation', 'I have run twenty such surveys for clients.')
            ->set('form.expected_amount', '30000')
            ->call('apply')
            ->assertHasNoErrors()
            ->assertSee('Application sent');

        $application = Application::query()->sole();
        expect($application->user_id)->toBe($this->contributor->id)
            ->and($application->open_role_id)->toBe($role->id)
            ->and($application->expected_amount)->toBe('30000.00');

        Livewire::actingAs($this->contributor)->test(Show::class, ['slug' => $role->slug])
            ->assertDontSee('Send application')->assertSee('Your application');
    });

    it('validates the answers', function () {
        $role = OpenRole::factory()->published()->create();

        Livewire::actingAs($this->contributor)->test(Show::class, ['slug' => $role->slug])
            ->set('form.motivation', 'too short')
            ->set('form.expected_amount', '-5')
            ->call('apply')
            ->assertHasErrors(['form.motivation', 'form.expected_amount']);

        expect(Application::query()->count())->toBe(0);
    });

    it('404s on a role they may not see and on drafts', function () {
        foreach ([OpenRole::factory()->published()->inviteOnly()->create(), OpenRole::factory()->create(), OpenRole::factory()->status(OpenRoleStatus::Archived)->create()] as $role) {
            asUser($this->contributor)->get('/app/contributor/discover/'.$role->slug)->assertNotFound();
        }
    });

    it('keeps a role open to someone who applied, after it closes, but takes no new application', function () {
        $role = OpenRole::factory()->published()->create(['title' => 'Closing role']);
        $application = Application::factory()->create(['open_role_id' => $role->id, 'user_id' => $this->contributor->id]);
        $role->forceFill(['status' => OpenRoleStatus::Closed])->save();

        Livewire::actingAs($this->contributor)->test(Show::class, ['slug' => $role->slug])
            ->assertOk()->assertSee('Closing role')->assertSee('Your application');

        asUser(contributorIn(OnboardingState::Active))->get('/app/contributor/discover/'.$role->slug)->assertNotFound();
    });

    it('does not take a second application and says why', function () {
        $role = OpenRole::factory()->published()->create();
        Application::factory()->create(['open_role_id' => $role->id, 'user_id' => $this->contributor->id]);

        Livewire::actingAs($this->contributor)->test(Show::class, ['slug' => $role->slug])
            ->set('form.motivation', 'A long enough second try at applying.')
            ->call('apply');

        expect(Application::query()->count())->toBe(1);
    });

    it('lets a withdrawn applicant apply again from the same page', function () {
        $role = OpenRole::factory()->published()->create();
        Application::factory()->status(ApplicationStatus::Withdrawn)->create(['open_role_id' => $role->id, 'user_id' => $this->contributor->id]);

        Livewire::actingAs($this->contributor)->test(Show::class, ['slug' => $role->slug])
            ->assertSee('You withdrew an earlier application')
            ->set('form.motivation', 'Now I have more time and more evidence.')
            ->call('apply')->assertSee('Application sent');

        expect(Application::query()->sole()->status)->toBe(ApplicationStatus::Submitted);
    });

    it('shows no form once the deadline has passed', function () {
        $role = OpenRole::factory()->published()->create(['deadline' => now()->subDay()]);

        Livewire::actingAs($this->contributor)->test(Show::class, ['slug' => $role->slug])
            ->assertDontSee('Send application')->assertSee('not taking applications');
    });
});

describe('my applications', function () {
    it('lists only their own applications', function () {
        $mine = Application::factory()->create(['user_id' => $this->contributor->id, 'open_role_id' => OpenRole::factory()->published()->create(['title' => 'My role'])->id]);
        Application::factory()->create(['open_role_id' => OpenRole::factory()->published()->create(['title' => 'Somebody else role'])->id]);

        Livewire::actingAs($this->contributor)->test(MyApplications::class)
            ->assertSee('My role')->assertDontSee('Somebody else role');
    });

    it('withdraws from the list', function () {
        $application = Application::factory()->create(['user_id' => $this->contributor->id]);

        Livewire::actingAs($this->contributor)->test(MyApplications::class)
            ->call('withdraw', $application->id)->assertSee('Application withdrawn');

        expect($application->fresh()->status)->toBe(ApplicationStatus::Withdrawn);
    });

    it('never lets one contributor act on another\'s application', function () {
        $other = Application::factory()->status(ApplicationStatus::Offered)->create();

        foreach (['withdraw', 'accept', 'decline'] as $method) {
            Livewire::actingAs($this->contributor)->test(MyApplications::class)->call($method, $other->id)->assertStatus(404);
        }

        expect($other->fresh()->status)->toBe(ApplicationStatus::Offered);
    });

    it('accepts an offer and becomes an active assignee', function () {
        Notification::fake();
        $project = Project::factory()->status(ProjectStatus::Staffing)->create();
        $role = OpenRole::factory()->published()->create(['project_id' => $project->id, 'title' => 'Lead analyst']);
        $offer = Application::factory()->status(ApplicationStatus::Offered)->create(['open_role_id' => $role->id, 'user_id' => $this->contributor->id]);

        Livewire::actingAs($this->contributor)->test(MyApplications::class)
            ->assertSee('Accept offer')
            ->call('accept', $offer->id)
            ->assertSee('Offer accepted');

        expect($offer->fresh()->status)->toBe(ApplicationStatus::Accepted)
            ->and(Assignment::query()->where('project_id', $project->id)->sole()->status)->toBe(AssignmentStatus::Active);
    });

    it('explains a blocked acceptance and leaves the offer open', function () {
        $project = Project::factory()->status(ProjectStatus::Draft)->create();
        $role = OpenRole::factory()->published()->create(['project_id' => $project->id]);
        $offer = Application::factory()->status(ApplicationStatus::Offered)->create(['open_role_id' => $role->id, 'user_id' => $this->contributor->id]);

        Livewire::actingAs($this->contributor)->test(MyApplications::class)
            ->call('accept', $offer->id)->assertSee('only possible while the project is staffing or active');

        expect($offer->fresh()->status)->toBe(ApplicationStatus::Offered);
    });

    it('declines an offer', function () {
        Notification::fake();
        $offer = Application::factory()->status(ApplicationStatus::Offered)->create(['user_id' => $this->contributor->id]);

        Livewire::actingAs($this->contributor)->test(MyApplications::class)->call('decline', $offer->id)->assertSee('Offer declined');

        expect($offer->fresh()->status)->toBe(ApplicationStatus::Declined);
        Notification::assertNothingSentTo($this->contributor, ApplicationUpdated::class);
    });
});

describe('recommendations', function () {
    beforeEach(function () {
        $this->skillA = Skill::factory()->create();
        $this->skillB = Skill::factory()->create();
        $this->contributor->skills()->attach([$this->skillA->id => ['level' => 'advanced'], $this->skillB->id => ['level' => 'advanced']]);
    });

    function roleWithSkills(array $skills, array $attrs = []): OpenRole
    {
        $role = OpenRole::factory()->published()->create($attrs);
        $role->skills()->attach(collect($skills)->pluck('id'));

        return $role;
    }

    it('recommends roles that share skills, most shared first, and nothing else', function () {
        $one = roleWithSkills([$this->skillA], ['title' => 'One shared']);
        $two = roleWithSkills([$this->skillA, $this->skillB], ['title' => 'Two shared']);
        roleWithSkills([Skill::factory()->create()], ['title' => 'No overlap']);
        roleWithSkills([], ['title' => 'No skills']);

        $titles = app(RoleRecommender::class)->for($this->contributor)->pluck('title')->all();

        expect($titles)->toBe(['Two shared', 'One shared']);
    });

    it('leaves out roles they cannot see, have applied to, or that are past the deadline', function () {
        $invite = roleWithSkills([$this->skillA], ['title' => 'Invite only']);
        $invite->update(['visibility' => 'invite_only']);
        $applied = roleWithSkills([$this->skillA], ['title' => 'Applied already']);
        Application::factory()->status(ApplicationStatus::Withdrawn)->create(['open_role_id' => $applied->id, 'user_id' => $this->contributor->id]);
        roleWithSkills([$this->skillA], ['title' => 'Too late', 'deadline' => now()->subDay()]);
        $draft = OpenRole::factory()->create(['title' => 'Draft']);
        $draft->skills()->attach($this->skillA);
        roleWithSkills([$this->skillA], ['title' => 'Fine']);

        expect(app(RoleRecommender::class)->for($this->contributor)->pluck('title')->all())->toBe(['Fine']);
    });

    it('respects availability and weekly hours', function () {
        roleWithSkills([$this->skillA], ['title' => 'Heavy', 'est_hours_min' => 30]);
        roleWithSkills([$this->skillA], ['title' => 'Light', 'est_hours_min' => 5]);

        $this->contributor->profile->update(['hours_per_week' => 10]);
        expect(app(RoleRecommender::class)->for($this->contributor->fresh())->pluck('title')->all())->toBe(['Light']);

        $this->contributor->profile->update(['availability' => Availability::Unavailable]);
        expect(app(RoleRecommender::class)->for($this->contributor->fresh()))->toBeEmpty();
    });

    it('recommends nothing to someone who is not active or has no skills', function () {
        roleWithSkills([$this->skillA]);

        expect(app(RoleRecommender::class)->for(contributorIn(OnboardingState::PendingReview)))->toBeEmpty()
            ->and(app(RoleRecommender::class)->for(contributorIn(OnboardingState::Active)))->toBeEmpty();
    });

    it('shows them on the dashboard with offers waiting, and hides the section when the flag is off', function () {
        roleWithSkills([$this->skillA], ['title' => 'Matching role']);
        Application::factory()->status(ApplicationStatus::Offered)->create(['user_id' => $this->contributor->id]);

        Livewire::actingAs($this->contributor)->test(Dashboard::class)
            ->assertSee('Matching role')->assertSee('You have an offer waiting');

        roleFlag(false);

        Livewire::actingAs($this->contributor)->test(Dashboard::class)
            ->assertDontSee('Matching role')->assertDontSee('offer waiting');
    });

    it('does not show recommendations or role links to contributors who are not active', function () {
        $pending = contributorIn(OnboardingState::PendingReview);
        $pending->skills()->attach($this->skillA, ['level' => 'advanced']);
        roleWithSkills([$this->skillA], ['title' => 'Matching role']);

        Livewire::actingAs($pending)->test(Dashboard::class)->assertDontSee('Matching role');
        asUser($pending)->get('/app/contributor')->assertOk()->assertDontSee('Discover');
    });
});

it('shows Discover and My applications in the sidebar only to active contributors with the flag on', function () {
    asUser($this->contributor)->get('/app/contributor')->assertSee('Discover')->assertSee('My applications');

    roleFlag(false);
    asUser($this->contributor)->get('/app/contributor')->assertDontSee('My applications');
});

it('issues the same number of queries for 3 rows as for 12 on the contributor pages', function () {
    $skill = Skill::factory()->create();
    $this->contributor->skills()->attach($skill, ['level' => 'advanced']);

    $seed = function (int $n) use ($skill): void {
        foreach (OpenRole::factory()->published()->count($n)->create() as $role) {
            $role->skills()->attach([$skill->id, Skill::factory()->create()->id]);
            Application::factory()->create(['open_role_id' => $role->id, 'user_id' => $this->contributor->id]);
        }
    };

    $count = function (string $path): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        asUser($this->contributor)->get($path)->assertOk();

        return count(DB::getQueryLog());
    };

    $paths = ['/app/contributor/discover', '/app/contributor/applications', '/app/contributor'];
    array_map($count, $paths); // warm the cached feature flags
    $seed(3);
    $few = array_map($count, $paths);
    $seed(9);
    $many = array_map($count, $paths);

    expect($many)->toBe($few);
});
