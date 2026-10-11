<?php

use App\Enums\AgreementType;
use App\Enums\FeatureFlag;
use App\Enums\ProjectStatus;
use App\Enums\ShowcaseConsent;
use App\Models\AgreementTemplate;
use App\Models\OpenRole;
use App\Models\Organization;
use App\Models\ProofOfWork;
use App\Models\Skill;
use App\Services\FeatureFlags\FeatureFlags;
use Database\Seeders\AgreementTemplateSeeder;

describe('organizations', function () {
    it('lists only verified, public organizations and opens only those', function () {
        $public = Organization::factory()->verified()->create(['name' => 'Kestrel Foods', 'slug' => 'kestrel-foods', 'is_public' => true, 'industry' => 'Manufacturing']);
        $private = Organization::factory()->verified()->create(['name' => 'Secret Holdings', 'slug' => 'secret-holdings', 'is_public' => false]);
        $pending = Organization::factory()->create(['name' => 'Pending Partners', 'slug' => 'pending-partners', 'is_public' => true, 'status' => 'pending']);

        $this->get(route('organizations.index'))->assertOk()->assertSee('Kestrel Foods')->assertDontSee('Secret Holdings')->assertDontSee('Pending Partners');
        $this->get(route('organizations.show', $public->slug))->assertOk()->assertSee('Manufacturing')->assertHeader('X-Robots-Tag', 'index, follow');
        $this->get(route('organizations.show', $private->slug))->assertNotFound();
        $this->get(route('organizations.show', $pending->slug))->assertNotFound();
        $this->get(route('organizations.show', 'no-such-organization'))->assertNotFound();
    });

    it('searches by name and treats wildcard characters as text', function () {
        Organization::factory()->verified()->create(['name' => 'Kestrel Foods', 'is_public' => true]);
        Organization::factory()->verified()->create(['name' => 'Bluepeak Logistics', 'is_public' => true]);

        $this->get(route('organizations.index', ['q' => 'Kestrel']))->assertSee('Kestrel Foods')->assertDontSee('Bluepeak Logistics');
        $this->get(route('organizations.index', ['q' => '%']))->assertOk()->assertDontSee('Kestrel Foods')->assertDontSee('Bluepeak Logistics');
    });

    it('is off when the directories flag is off', function () {
        $org = Organization::factory()->verified()->create(['slug' => 'kestrel-foods', 'is_public' => true]);
        app(FeatureFlags::class)->set(FeatureFlag::PublicDirectories, false);
        app(FeatureFlags::class)->flush();

        $this->get(route('organizations.index'))->assertNotFound();
        $this->get(route('organizations.show', $org->slug))->assertNotFound();
    });

    it('only links a website that starts with http or https', function () {
        Organization::factory()->verified()->create(['slug' => 'safe', 'is_public' => true, 'website' => 'https://example.test']);
        Organization::factory()->verified()->create(['slug' => 'unsafe', 'is_public' => true, 'website' => 'javascript:alert(1)']);

        $this->get(route('organizations.show', 'safe'))->assertSee('https://example.test');
        $this->get(route('organizations.show', 'unsafe'))->assertOk()->assertDontSee('javascript:alert(1)', false);
    });
});

describe('skills', function () {
    it('shows only the roles, contributors and proof the public may see', function () {
        $skill = Skill::factory()->create(['name' => 'Financial Modelling', 'slug' => 'financial-modelling']);

        $role = OpenRole::factory()->published()->public()->create(['title' => 'Public modeller']);
        $members = OpenRole::factory()->published()->create(['title' => 'Members modeller']);
        $draft = OpenRole::factory()->create(['title' => 'Draft modeller']);
        foreach ([$role, $members, $draft] as $r) {
            $r->skills()->attach($skill->id);
        }

        $public = publicContributor('Asha Rao');
        $private = publicContributor('Hidden Person', ['is_public' => false]);
        $public->skills()->attach($skill->id, ['level' => 'expert']);
        $private->skills()->attach($skill->id, ['level' => 'expert']);

        $ok = projectWithCompletedSeat(ProjectStatus::Closed, ['showcase_consent' => ShowcaseConsent::Anonymous]);
        $none = projectWithCompletedSeat(ProjectStatus::Closed, ['showcase_consent' => ShowcaseConsent::None]);
        $shown = ProofOfWork::factory()->published()->create(['user_id' => $ok['contributor']->id, 'project_id' => $ok['project']->id, 'title' => 'Shown model']);
        $blocked = ProofOfWork::factory()->published()->create(['user_id' => $none['contributor']->id, 'project_id' => $none['project']->id, 'title' => 'Blocked model']);
        $shown->skills()->attach($skill->id);
        $blocked->skills()->attach($skill->id);

        $this->get(route('skills.show', $skill->slug))->assertOk()
            ->assertSee('Financial Modelling')
            ->assertSee('Public modeller')->assertDontSee('Members modeller')->assertDontSee('Draft modeller')
            ->assertSee('Asha Rao')->assertDontSee('Hidden Person')
            ->assertSee('Shown model')->assertDontSee('Blocked model')
            ->assertHeader('X-Robots-Tag', 'index, follow');
    });

    it('answers 404 for an unknown or inactive skill', function () {
        Skill::factory()->create(['slug' => 'old-skill', 'is_active' => false]);

        $this->get(route('skills.show', 'old-skill'))->assertNotFound();
        $this->get(route('skills.show', 'missing'))->assertNotFound();
    });

    it('leaves out a list whose flag is off', function () {
        $skill = Skill::factory()->create(['slug' => 'research']);
        OpenRole::factory()->published()->public()->create(['title' => 'Public researcher'])->skills()->attach($skill->id);

        $this->get(route('skills.show', 'research'))->assertSee('Public researcher');

        app(FeatureFlags::class)->set(FeatureFlag::OpenRoles, false);
        app(FeatureFlags::class)->flush();

        $this->get(route('skills.show', 'research'))->assertOk()->assertDontSee('Public researcher');
    });
});

describe('terms and privacy', function () {
    it('answers 404 when there is no template, or only the seeded placeholder', function (AgreementType $type, string $route) {
        $this->get(route($route))->assertNotFound();

        $this->seed(AgreementTemplateSeeder::class);

        $this->get(route($route))->assertNotFound();
    })->with([[AgreementType::Terms, 'terms'], [AgreementType::Privacy, 'privacy']]);

    it('shows the active template once real wording is published', function (AgreementType $type, string $route) {
        $this->seed(AgreementTemplateSeeder::class);
        AgreementTemplate::query()->where('type', $type)->update(['is_active' => false]);
        AgreementTemplate::factory()->ofType($type)->create(['version' => 2, 'title' => 'Our '.$type->getLabel(), 'body_html' => '<p>The approved wording.</p>', 'is_active' => true]);

        $this->get(route($route))->assertOk()->assertSee('Our '.$type->getLabel())->assertSee('The approved wording.');
    })->with([[AgreementType::Terms, 'terms'], [AgreementType::Privacy, 'privacy']]);

    it('does not show an inactive template or one of another type', function () {
        AgreementTemplate::factory()->ofType(AgreementType::Terms)->inactive()->create(['body_html' => '<p>Old wording.</p>']);
        AgreementTemplate::factory()->ofType(AgreementType::Privacy)->create(['body_html' => '<p>Privacy wording.</p>', 'is_active' => true]);

        $this->get(route('terms'))->assertNotFound();
        $this->get(route('privacy'))->assertOk()->assertSee('Privacy wording.');
    });
});
