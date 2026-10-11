<?php

use App\Enums\AdminRole;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\ProofStatus;
use App\Enums\ReviewStatus;
use App\Enums\ShowcaseConsent;
use App\Filament\Admin\Resources\ProofOfWork\Pages\ListProofOfWork;
use App\Filament\Admin\Resources\ProofOfWork\Pages\ViewProofOfWork;
use App\Filament\Admin\Resources\Reviews\Pages\ListReviews;
use App\Filament\Admin\Resources\Reviews\Pages\ViewReview;
use App\Livewire\Client\Projects\ProjectReviews;
use App\Livewire\Contributor\Portfolio;
use App\Livewire\Contributor\Reputation;
use App\Models\ProofOfWork;
use App\Models\Review;
use Livewire\Livewire;

describe('route access', function () {
    it('sends guests to log in', function () {
        $seat = projectWithCompletedSeat();
        $proof = ProofOfWork::factory()->create(['user_id' => $seat['contributor']->id, 'project_id' => $seat['project']->id]);

        foreach (['/app/contributor/portfolio', "/app/contributor/portfolio/{$proof->uuid}", '/app/contributor/reputation', "/app/client/projects/{$seat['project']->uuid}/reviews"] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    });

    it('keeps the wrong role out of the contributor pages', function () {
        $seat = projectWithCompletedSeat();
        $proof = ProofOfWork::factory()->create(['user_id' => $seat['contributor']->id, 'project_id' => $seat['project']->id]);

        foreach ([$seat['owner'], adminUser()] as $actor) {
            foreach (['/app/contributor/portfolio', "/app/contributor/portfolio/{$proof->uuid}", '/app/contributor/reputation'] as $path) {
                asUser($actor)->get($path)->assertForbidden();
            }
        }
    });

    it('keeps non-active contributors out', function () {
        $pending = contributorIn(OnboardingState::PendingReview);

        foreach (['/app/contributor/portfolio', '/app/contributor/reputation'] as $path) {
            asUser($pending)->get($path)->assertForbidden();
        }
    });

    it('keeps the wrong role and other organizations out of the client review page', function () {
        $seat = projectWithCompletedSeat();
        $url = "/app/client/projects/{$seat['project']->uuid}/reviews";

        asUser($seat['contributor'])->get($url)->assertForbidden();
        asUser(adminUser())->get($url)->assertForbidden();
        asUser(clientUser())->get($url)->assertForbidden();
        asUser($seat['owner'])->get($url)->assertOk();
    });

    it('hides the review page until the project is accepted', function () {
        $seat = projectWithCompletedSeat(ProjectStatus::Delivered);

        asUser($seat['owner'])->get("/app/client/projects/{$seat['project']->uuid}/reviews")->assertNotFound();
    });

    it('lets a contributor open only their own proof of work', function () {
        $seat = projectWithCompletedSeat();
        $mine = ProofOfWork::factory()->create(['user_id' => $seat['contributor']->id, 'project_id' => $seat['project']->id]);
        $theirs = ProofOfWork::factory()->create(['user_id' => publicContributor('Peer Two')->id, 'project_id' => $seat['project']->id]);

        asUser($seat['contributor'])->get("/app/contributor/portfolio/{$mine->uuid}")->assertOk();
        asUser($seat['contributor'])->get("/app/contributor/portfolio/{$theirs->uuid}")->assertForbidden();
    });
});

describe('contributor portfolio', function () {
    it('lists only my items', function () {
        $seat = projectWithCompletedSeat(ProjectStatus::Closed);
        ProofOfWork::factory()->create(['user_id' => $seat['contributor']->id, 'project_id' => $seat['project']->id, 'title' => 'My own piece']);
        ProofOfWork::factory()->create(['user_id' => publicContributor('Peer Three')->id, 'project_id' => $seat['project']->id, 'title' => 'Someone elses piece']);

        Livewire::actingAs($seat['contributor'])->test(Portfolio\Index::class)->assertSee('My own piece')->assertDontSee('Someone elses piece');
    });

    it('saves a draft and sends it for approval', function () {
        $seat = projectWithCompletedSeat(ProjectStatus::Closed);
        $proof = ProofOfWork::factory()->create(['user_id' => $seat['contributor']->id, 'project_id' => $seat['project']->id, 'summary' => null, 'contribution_text' => null]);

        Livewire::actingAs($seat['contributor'])->test(Portfolio\Edit::class, ['proof' => $proof])
            ->set('summary', 'Sized the market.')
            ->set('contributionText', 'I built the model.')
            ->set('metrics', [['label' => 'Reports', 'value' => '12']])
            ->call('save')
            ->assertHasNoErrors()
            ->call('requestPublication')
            ->assertHasNoErrors()
            ->assertRedirect(route('contributor.portfolio'));

        expect($proof->refresh()->status)->toBe(ProofStatus::PendingApproval)
            ->and($proof->outcome_metrics)->toBe([['label' => 'Reports', 'value' => '12']]);
    });

    it('asks for a summary before publication', function () {
        $seat = projectWithCompletedSeat(ProjectStatus::Closed);
        $proof = ProofOfWork::factory()->create(['user_id' => $seat['contributor']->id, 'project_id' => $seat['project']->id, 'summary' => null]);

        Livewire::actingAs($seat['contributor'])->test(Portfolio\Edit::class, ['proof' => $proof])
            ->set('summary', '')
            ->call('requestPublication')
            ->assertHasErrors('publication');

        expect($proof->refresh()->status)->toBe(ProofStatus::Draft);
    });

    it('validates field lengths', function () {
        $seat = projectWithCompletedSeat(ProjectStatus::Closed);
        $proof = ProofOfWork::factory()->create(['user_id' => $seat['contributor']->id, 'project_id' => $seat['project']->id]);

        Livewire::actingAs($seat['contributor'])->test(Portfolio\Edit::class, ['proof' => $proof])
            ->set('title', str_repeat('a', 151))
            ->set('summary', str_repeat('a', 301))
            ->call('save')
            ->assertHasErrors(['title', 'summary']);
    });

    it('cannot change someone elses item through the component', function () {
        $seat = projectWithCompletedSeat(ProjectStatus::Closed);
        $theirs = ProofOfWork::factory()->create(['user_id' => publicContributor('Peer Four')->id, 'project_id' => $seat['project']->id]);

        Livewire::actingAs($seat['contributor'])->test(Portfolio\Edit::class, ['proof' => $theirs])->assertForbidden();
    });

    it('toggles visibility from the list', function () {
        $seat = projectWithCompletedSeat(ProjectStatus::Closed);
        $proof = ProofOfWork::factory()->create(['user_id' => $seat['contributor']->id, 'project_id' => $seat['project']->id]);

        Livewire::actingAs($seat['contributor'])->test(Portfolio\Index::class)->call('setVisibility', $proof->id, 'public');

        expect($proof->refresh()->visibility->value)->toBe('public');
    });

    it('cannot toggle someone elses item', function () {
        $seat = projectWithCompletedSeat(ProjectStatus::Closed);
        $theirs = ProofOfWork::factory()->create(['user_id' => publicContributor('Peer Five')->id, 'project_id' => $seat['project']->id]);

        Livewire::actingAs($seat['contributor'])->test(Portfolio\Index::class)->call('setVisibility', $theirs->id, 'public')->assertNotFound();

        expect($theirs->refresh()->visibility->value)->toBe('private');
    });
});

describe('contributor reputation page', function () {
    it('shows my facts and published reviews about me, never pending or internal ones', function () {
        $seat = projectWithCompletedSeat(ProjectStatus::Closed);
        Review::factory()->published()->create(['project_id' => $seat['project']->id, 'reviewee_id' => $seat['contributor']->id, 'comment' => 'Visible praise']);
        Review::factory()->create(['project_id' => $seat['project']->id, 'reviewee_id' => $seat['contributor']->id, 'comment' => 'Still pending']);
        Review::factory()->published()->internal()->create(['project_id' => $seat['project']->id, 'reviewee_id' => $seat['contributor']->id, 'comment' => 'Internal note']);

        Livewire::actingAs($seat['contributor'])->test(Reputation::class)
            ->assertSee('Visible praise')
            ->assertDontSee('Still pending')
            ->assertDontSee('Internal note');
    });

    it('lets me review the client once, after close', function () {
        $seat = projectWithCompletedSeat(ProjectStatus::Closed);

        $component = Livewire::actingAs($seat['contributor'])->test(Reputation::class)->assertSee('Review your clients');
        $component
            ->set("ratings.{$seat['project']->id}", ['clarity' => 4, 'communication' => 4, 'professionalism' => 5, 'feedback_time' => 3, 'fairness' => 5])
            ->call('submitClientReview', $seat['project']->id)
            ->assertHasNoErrors();

        expect(Review::query()->where('reviewer_id', $seat['contributor']->id)->count())->toBe(1);

        Livewire::actingAs($seat['contributor'])->test(Reputation::class)->assertDontSee('Review your clients');
    });

    it('shows no client review prompt for a project that is only accepted', function () {
        $seat = projectWithCompletedSeat();

        Livewire::actingAs($seat['contributor'])->test(Reputation::class)->assertDontSee('Review your clients');
    });
});

describe('client review page', function () {
    it('saves the showcase consent for an owner', function () {
        $seat = projectWithCompletedSeat();

        Livewire::actingAs($seat['owner'])->test(ProjectReviews::class, ['project' => $seat['project']])
            ->set('consent', 'named')
            ->call('saveConsent')
            ->assertHasNoErrors();

        expect($seat['project']->refresh()->showcase_consent)->toBe(ShowcaseConsent::Named);
    });

    it('refuses a viewer', function () {
        $seat = projectWithCompletedSeat();
        $viewer = clientUser($seat['organization'], OrgRole::Viewer);

        Livewire::actingAs($viewer)->test(ProjectReviews::class, ['project' => $seat['project']])
            ->set('consent', 'named')
            ->call('saveConsent')
            ->assertHasErrors('consent');

        expect($seat['project']->refresh()->showcase_consent)->toBe(ShowcaseConsent::None);
    });

    it('submits a review by assignment and never exposes the contributor id', function () {
        $seat = projectWithCompletedSeat();

        $component = Livewire::actingAs($seat['owner'])->test(ProjectReviews::class, ['project' => $seat['project']])
            ->assertSee('Meera, Research Analyst')
            ->assertDontSee('Nair')
            ->set("ratings.{$seat['assignment']->id}", ['quality' => 5, 'communication' => 4, 'reliability' => 5, 'timeliness' => 4])
            ->call('submitReview', $seat['assignment']->id)
            ->assertHasNoErrors();

        expect(Review::query()->where('reviewee_id', $seat['contributor']->id)->first()->status)->toBe(ReviewStatus::PendingModeration);
        $component->assertSee('Review sent');
    });

    it('shows a role only when the team is hidden', function () {
        $seat = projectWithCompletedSeat(ProjectStatus::Accepted, ['hide_team' => true]);

        Livewire::actingAs($seat['owner'])->test(ProjectReviews::class, ['project' => $seat['project']])
            ->assertSee('Team member, Research Analyst')
            ->assertDontSee('Meera');
    });

    it('rejects an assignment from another project', function () {
        $seat = projectWithCompletedSeat();
        $other = projectWithCompletedSeat();

        Livewire::actingAs($seat['owner'])->test(ProjectReviews::class, ['project' => $seat['project']])
            ->call('submitReview', $other['assignment']->id)
            ->assertNotFound();

        expect(Review::query()->count())->toBe(0);
    });

    it('is linked from the project page once accepted', function () {
        $seat = projectWithCompletedSeat();

        asUser($seat['owner'])->get(route('client.projects.show', $seat['project']))->assertOk()->assertSee('Reviews and showcase');
    });
});

describe('admin screens', function () {
    it('lets a delivery manager moderate a review from the queue', function () {
        $dm = adminUser(AdminRole::DeliveryManager);
        $review = Review::factory()->create();

        Livewire::actingAs($dm)->test(ListReviews::class)
            ->assertCanSeeTableRecords([$review])
            ->callTableAction('publish', $review);

        expect($review->refresh()->status)->toBe(ReviewStatus::Published);
    });

    it('lets a delivery manager open a review but keeps finance ops out', function () {
        $review = Review::factory()->create();

        Livewire::actingAs(adminUser(AdminRole::DeliveryManager))->test(ViewReview::class, ['record' => $review->getKey()])->assertOk();

        asUser(adminUser(AdminRole::FinanceOps))->get('/admin/reviews')->assertForbidden();
    });

    it('lets a community manager read reviews but not moderate them', function () {
        $review = Review::factory()->create();

        Livewire::actingAs(adminUser(AdminRole::CommunityManager))->test(ListReviews::class)
            ->assertCanSeeTableRecords([$review])
            ->assertTableActionHidden('publish', $review);
    });

    it('lets a community manager approve proof of work from the list', function () {
        $seat = projectWithCompletedSeat(ProjectStatus::Closed, ['showcase_consent' => ShowcaseConsent::Anonymous]);
        $proof = ProofOfWork::factory()->create(['user_id' => $seat['contributor']->id, 'project_id' => $seat['project']->id, 'status' => ProofStatus::PendingApproval]);

        Livewire::actingAs(adminUser(AdminRole::CommunityManager))->test(ListProofOfWork::class)
            ->assertCanSeeTableRecords([$proof])
            ->callTableAction('publish', $proof);

        expect($proof->refresh()->status)->toBe(ProofStatus::Published);
    });

    it('shows a blocked publish as a notice, not an error', function () {
        $seat = projectWithCompletedSeat(ProjectStatus::Closed);
        $proof = ProofOfWork::factory()->create(['user_id' => $seat['contributor']->id, 'project_id' => $seat['project']->id, 'status' => ProofStatus::PendingApproval]);

        Livewire::actingAs(adminUser(AdminRole::DeliveryManager))->test(ViewProofOfWork::class, ['record' => $proof->uuid])
            ->callAction('publish')
            ->assertNotified('That did not go through');

        expect($proof->refresh()->status)->toBe(ProofStatus::PendingApproval);
    });

    it('keeps finance ops out of proof of work', function () {
        asUser(adminUser(AdminRole::FinanceOps))->get('/admin/proof-of-work')->assertForbidden();
    });

    it('lets only a super admin add an internal review from the list', function () {
        $seat = projectWithCompletedSeat();

        Livewire::actingAs(adminUser(AdminRole::DeliveryManager))->test(ListReviews::class)->assertActionHidden('addInternal');

        Livewire::actingAs(adminUser(AdminRole::SuperAdmin))->test(ListReviews::class)
            ->callAction('addInternal', [
                'project_id' => $seat['project']->id,
                'contributor_id' => $seat['contributor']->id,
                'quality' => 4, 'communication' => 4, 'reliability' => 4, 'timeliness' => 4,
                'comment' => 'Internal only.',
            ])
            ->assertNotified('Internal review added');

        expect(Review::query()->where('direction', 'admin_to_contributor')->count())->toBe(1);
    });
});
