<?php

use App\Enums\CompensationType;
use App\Enums\FeatureFlag;
use App\Enums\OnboardingState;
use App\Enums\OpenRoleStatus;
use App\Enums\OpenRoleVisibility;
use App\Enums\WorkMode;
use App\Models\OpenRole;
use App\Models\Project;
use App\Models\ProjectType;
use App\Models\Skill;
use App\Services\FeatureFlags\FeatureFlags;

function switchOpenRoles(bool $on): void
{
    app(FeatureFlags::class)->set(FeatureFlag::OpenRoles, $on);
    app(FeatureFlags::class)->flush();
}

function allRoleStates(): array
{
    return [
        'public' => OpenRole::factory()->published()->public()->create(['title' => 'Public Analyst']),
        'members' => OpenRole::factory()->published()->create(['title' => 'Members Analyst']),
        'invite' => OpenRole::factory()->published()->inviteOnly()->create(['title' => 'Invite Analyst']),
        'draft' => OpenRole::factory()->public()->create(['title' => 'Draft Analyst']),
        'closed' => OpenRole::factory()->status(OpenRoleStatus::Closed)->public()->create(['title' => 'Closed Analyst']),
        'filled' => OpenRole::factory()->status(OpenRoleStatus::Filled)->public()->create(['title' => 'Filled Analyst']),
        'archived' => OpenRole::factory()->status(OpenRoleStatus::Archived)->public()->create(['title' => 'Archived Analyst']),
    ];
}

it('renders the list on an empty database', function () {
    $this->get('/opportunities')->assertOk()->assertSee('No roles match');
});

it('lists only public published roles', function () {
    $roles = allRoleStates();

    $this->get('/opportunities')->assertOk()
        ->assertSee('Public Analyst')
        ->assertDontSee('Members Analyst')
        ->assertDontSee('Invite Analyst')
        ->assertDontSee('Draft Analyst')
        ->assertDontSee('Closed Analyst')
        ->assertDontSee('Filled Analyst')
        ->assertDontSee('Archived Analyst');

    $this->get('/opportunities/'.$roles['public']->slug)->assertOk()->assertSee('Public Analyst');
});

it('returns 404 for every role the public may not see, never 403', function () {
    $roles = allRoleStates();

    foreach (['members', 'invite', 'draft', 'closed', 'filled', 'archived'] as $key) {
        $this->get('/opportunities/'.$roles[$key]->slug)->assertNotFound();
    }

    $this->get('/opportunities/not-a-real-role')->assertNotFound();
});

it('shows signed-in contributors, clients and admins the same public set', function () {
    allRoleStates();

    foreach ([contributorIn(OnboardingState::Active), clientUser(), adminUser()] as $user) {
        asUser($user)->get('/opportunities')->assertOk()
            ->assertSee('Public Analyst')
            ->assertDontSee('Members Analyst')
            ->assertDontSee('Invite Analyst');
    }
});

it('does not reveal an invited contributor\'s invite-only role on the public page', function () {
    $invite = allRoleStates()['invite'];
    $contributor = contributorIn(OnboardingState::Active);
    $invite->invitedUsers()->attach($contributor->id);

    asUser($contributor)->get('/opportunities')->assertDontSee('Invite Analyst');
    asUser($contributor)->get('/opportunities/'.$invite->slug)->assertNotFound();
});

it('hides everything, list, detail and sitemap, when the open_roles flag is off', function () {
    $roles = allRoleStates();
    switchOpenRoles(false);

    $this->get('/opportunities')->assertNotFound();
    $this->get('/opportunities/'.$roles['public']->slug)->assertNotFound();

    $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();
    expect($sitemap)->not->toContain('opportunities');
});

it('lists only public published roles in the sitemap', function () {
    $roles = allRoleStates();

    $sitemap = $this->get('/sitemap.xml')->assertOk()->getContent();

    expect($sitemap)->toContain(route('opportunities.index'))
        ->and($sitemap)->toContain(route('opportunities.show', $roles['public']->slug));

    foreach (['members', 'invite', 'draft', 'closed', 'filled', 'archived'] as $key) {
        expect($sitemap)->not->toContain($roles[$key]->slug);
    }
});

it('returns to the sitemap when the flag is switched back on', function () {
    $role = OpenRole::factory()->published()->public()->create();
    switchOpenRoles(false);
    switchOpenRoles(true);

    expect($this->get('/sitemap.xml')->getContent())->toContain($role->slug);
});

describe('filters', function () {
    beforeEach(function () {
        $this->skill = Skill::factory()->create(['name' => 'Excel Modelling', 'slug' => 'excel-modelling']);
        $this->type = ProjectType::factory()->create(['name' => 'Financial Modelling', 'slug' => 'financial-modelling']);

        $this->match = OpenRole::factory()->published()->public()->create([
            'title' => 'Model builder', 'summary' => 'Build a three statement model.', 'work_mode' => WorkMode::Hybrid,
            'compensation_type' => CompensationType::Stipend, 'duration_label' => '6 weeks', 'project_type_id' => $this->type->id,
        ]);
        $this->match->skills()->attach($this->skill);

        $this->other = OpenRole::factory()->published()->public()->create([
            'title' => 'Survey helper', 'summary' => 'Help run a survey.', 'work_mode' => WorkMode::Remote,
            'compensation_type' => CompensationType::Paid, 'duration_label' => '2 weeks',
        ]);
    });

    it('filters by each of skill, type, work mode, compensation and duration', function (array $query) {
        $this->get('/opportunities?'.http_build_query($query))->assertOk()
            ->assertSee('Model builder')->assertDontSee('Survey helper');
    })->with([
        'skill' => [['skill' => 'excel-modelling']],
        'type' => [['type' => 'financial-modelling']],
        'work mode' => [['work_mode' => 'hybrid']],
        'compensation' => [['compensation' => 'stipend']],
        'duration' => [['duration' => '6 weeks']],
    ]);

    it('searches title and summary', function () {
        $this->get('/opportunities?q=three+statement')->assertOk()->assertSee('Model builder')->assertDontSee('Survey helper');
        $this->get('/opportunities?q=survey')->assertOk()->assertSee('Survey helper')->assertDontSee('Model builder');
        $this->get('/opportunities?q=zzzz')->assertOk()->assertSee('No roles match');
    });

    it('combines filters and treats LIKE wildcards in the search literally', function () {
        $this->get('/opportunities?skill=excel-modelling&work_mode=remote')->assertOk()->assertSee('No roles match');
        $this->get('/opportunities?q=%25')->assertOk()->assertSee('No roles match');
    });

    it('rejects unknown enum values and over-long input instead of widening the list', function () {
        $this->get('/opportunities?work_mode=anything')->assertSessionHasErrors('work_mode');
        $this->get('/opportunities?compensation=free')->assertSessionHasErrors('compensation');
        $this->get('/opportunities?q='.str_repeat('a', 101))->assertSessionHasErrors('q');
    });

    it('never lets a filter surface a role that is not public', function () {
        $hidden = OpenRole::factory()->published()->create(['title' => 'Hidden members role', 'work_mode' => WorkMode::Hybrid]);
        $hidden->skills()->attach($this->skill);

        $this->get('/opportunities?skill=excel-modelling&work_mode=hybrid')->assertDontSee('Hidden members role');
    });

    it('offers only filter choices the visitor can actually find', function () {
        $hidden = OpenRole::factory()->published()->create(['duration_label' => '99 months']);
        $draftSkill = Skill::factory()->create(['name' => 'Secret Draft Skill']);
        OpenRole::factory()->public()->create()->skills()->attach($draftSkill);

        $this->get('/opportunities')->assertSee('6 weeks')->assertDontSee('99 months')->assertDontSee('Secret Draft Skill');
    });
});

describe('detail page', function () {
    it('shows the role, its tags and structured data, and nothing about the client or project', function () {
        $project = Project::factory()->create(['title' => 'Confidential client project']);
        $role = OpenRole::factory()->published()->public()->create([
            'title' => 'Research analyst', 'project_id' => $project->id, 'summary' => 'Analyse the market.',
            'tasks_list' => ['Interview buyers'], 'learning_outcomes' => ['Interview technique'],
        ]);

        $html = $this->get('/opportunities/'.$role->slug)->assertOk()
            ->assertHeader('X-Robots-Tag', 'index, follow')
            ->getContent();

        expect($html)->toContain('<title>Research analyst | ')
            ->and($html)->toContain('<link rel="canonical" href="'.route('opportunities.show', $role->slug).'">')
            ->and($html)->toContain('property="og:title" content="Research analyst | Groibe"')
            ->and($html)->toContain('"@type":"BreadcrumbList"')
            ->and($html)->toContain('Interview buyers')
            ->and($html)->toContain('Interview technique')
            ->and($html)->not->toContain('Confidential client project')
            ->and($html)->not->toContain('—');
    });

    it('escapes role text and keeps a script out of the page', function () {
        $role = OpenRole::factory()->published()->public()->create([
            'title' => 'Plain title', 'description' => "<script>alert('x')</script>\nSecond line",
        ]);

        $html = $this->get('/opportunities/'.$role->slug)->assertOk()->getContent();

        expect($html)->not->toContain("<script>alert('x')</script>")->and($html)->toContain('&lt;script&gt;');
    });

    it('shows the right call to action to each kind of visitor', function () {
        $role = OpenRole::factory()->published()->public()->create();
        $url = '/opportunities/'.$role->slug;

        $this->get($url)->assertSee('Log in to apply');
        asUser(contributorIn(OnboardingState::Active))->get($url)->assertSee('Apply for this role')->assertDontSee('Log in to apply');
        asUser(contributorIn(OnboardingState::PendingReview))->get($url)->assertSee('Applying is open to approved Groibe contributors');
    });

    it('says applications have closed after the deadline but still shows the role', function () {
        $role = OpenRole::factory()->published()->public()->create(['deadline' => now()->subDay()]);

        $this->get('/opportunities/'.$role->slug)->assertOk()->assertSee('Applications for this role have closed.')->assertDontSee('Log in to apply');
    });
});

it('paginates the list', function () {
    OpenRole::factory()->published()->public()->count(15)->create();

    $this->get('/opportunities')->assertOk()->assertSee('page=2', false);
    $this->get('/opportunities?page=2')->assertOk();
});

it('issues the same number of queries for 3 roles as for 12 on the public list', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->get('/opportunities')->assertOk();

        return count(DB::getQueryLog());
    };

    test()->get('/opportunities'); // warm the cached feature flags, which are read once per cache lifetime

    foreach (OpenRole::factory()->published()->public()->count(3)->create() as $role) {
        $role->skills()->attach(Skill::factory()->create());
    }
    $few = $count();

    foreach (OpenRole::factory()->published()->public()->count(9)->create() as $role) {
        $role->skills()->attach(Skill::factory()->create());
    }

    expect($count())->toBe($few);
});

it('publishes no em dashes and no AI wording on the opportunity pages', function () {
    $role = OpenRole::factory()->published()->public()->create();

    foreach (['/opportunities', '/opportunities/'.$role->slug] as $path) {
        $text = strip_tags($this->get($path)->getContent());

        expect($text)->not->toContain('—')->and($text)->not->toMatch('/\b(AI|artificial intelligence|machine learning|chatbot)\b/i');
    }
});

it('keeps the visibility enum labels readable', function () {
    expect(OpenRoleVisibility::InviteOnly->getLabel())->toBe('Invite only')
        ->and(WorkMode::Onsite->getLabel())->toBe('On site');
});
