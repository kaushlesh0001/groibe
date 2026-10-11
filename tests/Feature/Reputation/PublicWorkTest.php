<?php

use App\Enums\FeatureFlag;
use App\Enums\ProjectStatus;
use App\Enums\ProofStatus;
use App\Enums\ProofVisibility;
use App\Enums\ShowcaseConsent;
use App\Models\ProofOfWork;
use App\Models\ReputationFact;
use App\Models\Skill;
use App\Services\FeatureFlags\FeatureFlags;

/** A published, public item on a closed project with the given consent. @return array{0: ProofOfWork, 1: array<string, mixed>} */
function showcased(ShowcaseConsent $consent, array $proof = []): array
{
    $seat = projectWithCompletedSeat(ProjectStatus::Closed, ['showcase_consent' => $consent, 'title' => 'Acme Logistics Rollout']);
    $item = ProofOfWork::factory()->published()->create([
        'user_id' => $seat['contributor']->id,
        'project_id' => $seat['project']->id,
        'title' => 'Regional market sizing',
        'summary' => 'Sized the market for a regional operator.',
        'contribution_text' => 'Built the model.',
        ...$proof,
    ]);

    return [$item, $seat];
}

it('shows a published, public, consented item on the list, the page and the sitemap', function () {
    [$item] = showcased(ShowcaseConsent::Anonymous);

    $this->get(route('work.index'))->assertOk()->assertSee('Regional market sizing');
    $this->get(route('work.show', $item->slug))->assertOk()->assertSee('Built the model.')->assertHeader('X-Robots-Tag', 'index, follow');
    $this->get(route('sitemap'))->assertOk()->assertSee(route('work.show', $item->slug), false);
});

it('never shows an item whose project consent is none, anywhere', function () {
    [$item, $seat] = showcased(ShowcaseConsent::None);

    $this->get(route('work.show', $item->slug))->assertNotFound();
    $this->get(route('work.index'))->assertOk()->assertDontSee('Regional market sizing');
    $this->get(route('work.index', ['q' => 'market']))->assertOk()->assertDontSee('Regional market sizing');
    $this->get(route('sitemap'))->assertDontSee($item->slug);
    $this->get(route('contributors.show', $seat['contributor']->profile->slug))->assertOk()->assertDontSee('Regional market sizing');
});

it('pulls an item from every public surface the moment consent goes back to none', function () {
    [$item, $seat] = showcased(ShowcaseConsent::Named);
    $this->get(route('work.show', $item->slug))->assertOk();

    $seat['project']->forceFill(['showcase_consent' => ShowcaseConsent::None])->save();

    $this->get(route('work.show', $item->slug))->assertNotFound();
    $this->get(route('work.index'))->assertDontSee('Regional market sizing');
    $this->get(route('sitemap'))->assertDontSee($item->slug);
    $this->get(route('contributors.show', $seat['contributor']->profile->slug))->assertDontSee('Regional market sizing');
});

it('hides items that are not published, not public, or have lost their project', function (array $attributes) {
    [$item] = showcased(ShowcaseConsent::Anonymous, $attributes);

    $this->get(route('work.show', $item->slug))->assertNotFound();
    $this->get(route('work.index'))->assertDontSee('Regional market sizing');
    $this->get(route('sitemap'))->assertDontSee($item->slug);
})->with([
    'draft' => [['status' => ProofStatus::Draft]],
    'pending' => [['status' => ProofStatus::PendingApproval]],
    'hidden' => [['status' => ProofStatus::Hidden]],
    'private' => [['visibility' => ProofVisibility::Private]],
    'members only' => [['visibility' => ProofVisibility::Members]],
    'no project' => [['project_id' => null]],
]);

it('does not name the client unless the consent is named', function () {
    [$anonymous] = showcased(ShowcaseConsent::Anonymous);
    $this->get(route('work.show', $anonymous->slug))->assertSee('Confidential client, Logistics')->assertDontSee('Acme Logistics Rollout');

    [$named, $seat] = showcased(ShowcaseConsent::Named);
    $this->get(route('work.show', $named->slug))->assertSee($seat['organization']->name);
});

it('never leaks the project title, the client name or the contributor email under anonymous consent', function () {
    [$item, $seat] = showcased(ShowcaseConsent::Anonymous);

    $this->get(route('work.show', $item->slug))
        ->assertDontSee('Acme Logistics Rollout')
        ->assertDontSee($seat['organization']->name)
        ->assertDontSee($seat['contributor']->email);
});

it('credits the contributor by name only when their profile is public', function () {
    [$item, $seat] = showcased(ShowcaseConsent::Anonymous);
    $this->get(route('work.show', $item->slug))->assertSee('Meera Nair');

    $seat['contributor']->profile->forceFill(['is_public' => false])->save();
    $this->get(route('work.show', $item->slug))->assertOk()->assertDontSee('Meera Nair');
});

it('turns the whole section off with the flag', function () {
    [$item] = showcased(ShowcaseConsent::Anonymous);
    app(FeatureFlags::class)->set(FeatureFlag::ProofOfWorkPublic, false);
    app(FeatureFlags::class)->flush();

    $this->get(route('work.index'))->assertNotFound();
    $this->get(route('work.show', $item->slug))->assertNotFound();
    $this->get(route('sitemap'))->assertDontSee(route('work.index'), false);
});

it('filters by skill and searches title and summary', function () {
    [$item] = showcased(ShowcaseConsent::Anonymous);
    $skill = Skill::factory()->create(['name' => 'Modelling', 'slug' => 'modelling']);
    $item->skills()->attach($skill->id);
    [$other] = showcased(ShowcaseConsent::Anonymous, ['title' => 'Brand refresh', 'summary' => 'Visual identity work.']);

    $this->get(route('work.index', ['skill' => $skill->slug]))->assertSee('Regional market sizing')->assertDontSee('Brand refresh');
    $this->get(route('work.index', ['q' => 'identity']))->assertSee('Brand refresh')->assertDontSee('Regional market sizing');
});

it('shows reputation facts and consented work on a public profile only', function () {
    [$item, $seat] = showcased(ShowcaseConsent::Anonymous);
    ReputationFact::factory()->create(['user_id' => $seat['contributor']->id, 'projects_completed' => 3, 'reviews_count' => 2, 'avg_rating' => 4.5, 'tasks_total' => 4, 'tasks_approved_first_pass' => 3]);

    $this->get(route('contributors.show', $seat['contributor']->profile->slug))
        ->assertOk()
        ->assertSee('Track record')
        ->assertSee('75%')
        ->assertSee('Regional market sizing');

    $seat['contributor']->profile->forceFill(['is_public' => false])->save();
    $this->actingAs($seat['contributor'])->get(route('contributors.show', $seat['contributor']->profile->slug))
        ->assertOk()
        ->assertDontSee('Track record');
});
