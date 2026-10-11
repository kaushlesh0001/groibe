<?php

use App\Actions\Reviews\HideReview;
use App\Actions\Reviews\PublishReview;
use App\Actions\Reviews\SubmitClientReview;
use App\Actions\Reviews\SubmitContributorReview;
use App\Actions\Reviews\SubmitInternalReview;
use App\Enums\AdminRole;
use App\Enums\FeatureFlag;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\ReviewDirection;
use App\Enums\ReviewStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\ReputationFact;
use App\Models\Review;
use App\Notifications\ReviewSubmitted;
use App\Services\FeatureFlags\FeatureFlags;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

const CONTRIBUTOR_RATINGS = ['quality' => 5, 'communication' => 4, 'reliability' => 5, 'timeliness' => 4];
const CLIENT_RATINGS = ['clarity' => 4, 'communication' => 4, 'professionalism' => 5, 'feedback_time' => 3, 'fairness' => 5];

it('lets the client owner review a contributor once the project is accepted, starting in moderation', function () {
    Notification::fake();
    $seat = projectWithCompletedSeat();

    $review = app(SubmitContributorReview::class)($seat['project'], $seat['owner'], $seat['contributor'], CONTRIBUTOR_RATINGS, 'Solid work.');

    expect($review->status)->toBe(ReviewStatus::PendingModeration)
        ->and($review->direction)->toBe(ReviewDirection::ClientToContributor)
        ->and($review->reviewee_id)->toBe($seat['contributor']->id)
        ->and($review->published_at)->toBeNull();
});

it('tells admins who can moderate that a review is waiting', function () {
    Notification::fake();
    $dm = adminUser(AdminRole::DeliveryManager);
    $seat = projectWithCompletedSeat();

    app(SubmitContributorReview::class)($seat['project'], $seat['owner'], $seat['contributor'], CONTRIBUTOR_RATINGS);

    Notification::assertSentTo($dm, ReviewSubmitted::class);
});

it('lets a client manager review but not a viewer, another organization, or a contributor', function () {
    $seat = projectWithCompletedSeat();
    $manager = clientUser($seat['organization'], OrgRole::Manager);
    $viewer = clientUser($seat['organization'], OrgRole::Viewer);
    $outsider = clientUser();

    expect(app(SubmitContributorReview::class)($seat['project'], $manager, $seat['contributor'], CONTRIBUTOR_RATINGS))->toBeInstanceOf(Review::class);

    foreach ([$viewer, $outsider, $seat['contributor']] as $denied) {
        expect(fn () => app(SubmitContributorReview::class)($seat['project'], $denied, $seat['contributor'], CONTRIBUTOR_RATINGS))
            ->toThrow(AuthorizationException::class);
    }
});

it('does not allow a client review before the project is accepted', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Delivered);

    expect(fn () => app(SubmitContributorReview::class)($seat['project'], $seat['owner'], $seat['contributor'], CONTRIBUTOR_RATINGS))
        ->toThrow(AuthorizationException::class);
});

it('only lets a client review a contributor who completed work on that project', function () {
    $seat = projectWithCompletedSeat();
    $stranger = publicContributor('Someone Else');

    expect(fn () => app(SubmitContributorReview::class)($seat['project'], $seat['owner'], $stranger, CONTRIBUTOR_RATINGS))
        ->toThrow(ActionBlocked::class, 'did not complete');
});

it('rejects ratings outside 1 to 5 or missing', function (array $ratings) {
    $seat = projectWithCompletedSeat();

    expect(fn () => app(SubmitContributorReview::class)($seat['project'], $seat['owner'], $seat['contributor'], $ratings))
        ->toThrow(ActionBlocked::class, 'rate every criterion');
})->with([
    'zero' => [[...CONTRIBUTOR_RATINGS, 'quality' => 0]],
    'six' => [[...CONTRIBUTOR_RATINGS, 'quality' => 6]],
    'missing' => [['quality' => 5, 'communication' => 4, 'reliability' => 5]],
    'text' => [[...CONTRIBUTOR_RATINGS, 'timeliness' => 'great']],
]);

it('keeps one review per reviewer and reviewee', function () {
    $seat = projectWithCompletedSeat();

    app(SubmitContributorReview::class)($seat['project'], $seat['owner'], $seat['contributor'], CONTRIBUTOR_RATINGS);

    expect(fn () => app(SubmitContributorReview::class)($seat['project'], $seat['owner'], $seat['contributor'], CONTRIBUTOR_RATINGS))
        ->toThrow(ActionBlocked::class, 'already reviewed')
        ->and(Review::query()->count())->toBe(1);
});

it('lets a contributor review the client only after close, only for their own completed seat', function () {
    $accepted = projectWithCompletedSeat();

    expect(fn () => app(SubmitClientReview::class)($accepted['project'], $accepted['contributor'], CLIENT_RATINGS))
        ->toThrow(AuthorizationException::class);

    $closed = projectWithCompletedSeat(ProjectStatus::Closed);
    $other = publicContributor('Not On It');

    expect(fn () => app(SubmitClientReview::class)($closed['project'], $other, CLIENT_RATINGS))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SubmitClientReview::class)($closed['project'], $closed['owner'], CLIENT_RATINGS))->toThrow(AuthorizationException::class);

    $review = app(SubmitClientReview::class)($closed['project'], $closed['contributor'], CLIENT_RATINGS, 'Clear brief.');

    expect($review->direction)->toBe(ReviewDirection::ContributorToClient)
        ->and($review->reviewee_id)->toBe($closed['organization']->id)
        ->and($review->status)->toBe(ReviewStatus::PendingModeration);
});

it('switches contributor reviews of clients off with the flag', function () {
    $closed = projectWithCompletedSeat(ProjectStatus::Closed);
    app(FeatureFlags::class)->set(FeatureFlag::ContributorReviewsOfClients, false);
    app(FeatureFlags::class)->flush();

    expect(fn () => app(SubmitClientReview::class)($closed['project'], $closed['contributor'], CLIENT_RATINGS))
        ->toThrow(ActionBlocked::class, 'not available');
});

it('lets only a super admin add an internal review, which is never counted', function () {
    $seat = projectWithCompletedSeat();
    $super = adminUser(AdminRole::SuperAdmin);
    $dm = adminUser(AdminRole::DeliveryManager);

    expect(fn () => app(SubmitInternalReview::class)($seat['project'], $dm, $seat['contributor'], CONTRIBUTOR_RATINGS))
        ->toThrow(AuthorizationException::class);

    $review = app(SubmitInternalReview::class)($seat['project'], $super, $seat['contributor'], CONTRIBUTOR_RATINGS, 'Needs coaching on estimates.');
    app(PublishReview::class)($review, $super);

    $facts = ReputationFact::query()->where('user_id', $seat['contributor']->id)->first();

    expect($review->direction)->toBe(ReviewDirection::AdminToContributor)
        ->and($facts)->toBeNull();
});

it('moderates: publish and hide move the state, log it, and refresh reputation', function () {
    $seat = projectWithCompletedSeat();
    $dm = adminUser(AdminRole::DeliveryManager);
    $review = app(SubmitContributorReview::class)($seat['project'], $seat['owner'], $seat['contributor'], CONTRIBUTOR_RATINGS);

    $published = app(PublishReview::class)($review, $dm, 'Fine.');

    expect($published->status)->toBe(ReviewStatus::Published)
        ->and($published->published_at)->not->toBeNull()
        ->and((float) ReputationFact::query()->where('user_id', $seat['contributor']->id)->firstOrFail()->avg_rating)->toBe(4.5);

    app(HideReview::class)($published, $dm);

    expect($review->refresh()->status)->toBe(ReviewStatus::Hidden)
        ->and(ReputationFact::query()->where('user_id', $seat['contributor']->id)->firstOrFail()->reviews_count)->toBe(0)
        ->and(Activity::query()->where('log_name', 'review')->whereIn('event', ['published', 'hidden'])->count())->toBe(2);
});

it('refuses illegal moderation moves', function () {
    $seat = projectWithCompletedSeat();
    $dm = adminUser(AdminRole::DeliveryManager);
    $review = app(SubmitContributorReview::class)($seat['project'], $seat['owner'], $seat['contributor'], CONTRIBUTOR_RATINGS);
    app(PublishReview::class)($review, $dm);

    expect(fn () => app(PublishReview::class)($review->refresh(), $dm))->toThrow(IllegalTransition::class);

    app(HideReview::class)($review->refresh(), $dm);

    expect(fn () => app(HideReview::class)($review->refresh(), $dm))->toThrow(IllegalTransition::class);
});

it('keeps moderation to admins with the approve permission', function () {
    $seat = projectWithCompletedSeat();
    $review = app(SubmitContributorReview::class)($seat['project'], $seat['owner'], $seat['contributor'], CONTRIBUTOR_RATINGS);

    foreach ([$seat['owner'], $seat['contributor'], adminUser(AdminRole::CommunityManager), adminUser(AdminRole::FinanceOps)] as $denied) {
        expect(fn () => app(PublishReview::class)($review, $denied))->toThrow(AuthorizationException::class);
    }
});

it('shows a contributor only the published client reviews written about them', function () {
    $seat = projectWithCompletedSeat();
    $mine = Review::factory()->published()->create(['project_id' => $seat['project']->id, 'reviewee_id' => $seat['contributor']->id]);
    $pending = Review::factory()->create(['project_id' => $seat['project']->id, 'reviewee_id' => $seat['contributor']->id]);
    $internal = Review::factory()->published()->internal()->create(['project_id' => $seat['project']->id, 'reviewee_id' => $seat['contributor']->id]);
    $ofClient = Review::factory()->published()->ofClient($seat['organization'])->create(['project_id' => $seat['project']->id, 'reviewer_id' => $seat['contributor']->id]);
    $someoneElses = Review::factory()->published()->create(['project_id' => $seat['project']->id]);

    $contributor = $seat['contributor'];

    expect(Gate::forUser($contributor)->allows('view', $mine))->toBeTrue()
        ->and(Gate::forUser($contributor)->allows('view', $pending))->toBeFalse()
        ->and(Gate::forUser($contributor)->allows('view', $internal))->toBeFalse()
        ->and(Gate::forUser($contributor)->allows('view', $someoneElses))->toBeFalse()
        // Their own review of the client is theirs to read, but nobody else's contributor can read it.
        ->and(Gate::forUser($contributor)->allows('view', $ofClient))->toBeTrue()
        ->and(Gate::forUser(publicContributor('Another'))->allows('view', $ofClient))->toBeFalse();
});

it('keeps client-written reviews away from other clients and organization reviews from clients', function () {
    $seat = projectWithCompletedSeat();
    $ofClient = Review::factory()->published()->ofClient($seat['organization'])->create(['project_id' => $seat['project']->id]);
    $clientReview = Review::factory()->published()->create(['project_id' => $seat['project']->id, 'reviewer_id' => $seat['owner']->id, 'reviewee_id' => $seat['contributor']->id]);

    expect(Gate::forUser($seat['owner'])->allows('view', $clientReview))->toBeTrue()
        ->and(Gate::forUser($seat['owner'])->allows('view', $ofClient))->toBeFalse()
        ->and(Gate::forUser(clientUser())->allows('view', $clientReview))->toBeFalse();
});

it('gives admins their matrix rights on reviews', function () {
    $review = Review::factory()->create();

    expect(Gate::forUser(adminUser(AdminRole::DeliveryManager))->allows('moderate', $review))->toBeTrue()
        ->and(Gate::forUser(adminUser(AdminRole::DeliveryManager))->allows('create', Review::class))->toBeFalse()
        ->and(Gate::forUser(adminUser(AdminRole::CommunityManager))->allows('view', $review))->toBeTrue()
        ->and(Gate::forUser(adminUser(AdminRole::CommunityManager))->allows('moderate', $review))->toBeFalse()
        ->and(Gate::forUser(adminUser(AdminRole::FinanceOps))->allows('view', $review))->toBeFalse()
        ->and(Gate::forUser(adminUser(AdminRole::SuperAdmin))->allows('create', Review::class))->toBeTrue();
});
