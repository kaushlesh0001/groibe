<?php

use App\Actions\Projects\SetShowcaseConsent;
use App\Actions\Reputation\HideProofOfWork;
use App\Actions\Reputation\PublishProofOfWork;
use App\Actions\Reputation\RequestProofPublication;
use App\Actions\Reputation\ReturnProofToDraft;
use App\Actions\Reputation\SetProofVisibility;
use App\Actions\Reputation\UpdateProofOfWork;
use App\Enums\AdminRole;
use App\Enums\ClientDisplay;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\ProofStatus;
use App\Enums\ProofVisibility;
use App\Enums\ShowcaseConsent;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\ProofOfWork;
use App\Notifications\ProofOfWorkChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

/** A draft proof of work for the seat's contributor, with wording ready to submit. */
function draftFor(array $seat, array $attributes = []): ProofOfWork
{
    return ProofOfWork::factory()->create([
        'user_id' => $seat['contributor']->id,
        'project_id' => $seat['project']->id,
        'title' => 'Research Analyst: Market Research',
        ...$attributes,
    ]);
}

it('lets the owner edit the wording of a draft and clean up metrics', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Closed);
    $proof = draftFor($seat);

    $updated = app(UpdateProofOfWork::class)($proof, $seat['contributor'], [
        'title' => 'Market sizing for a logistics firm',
        'summary' => 'Sized the regional market.',
        'contribution_text' => 'I built the model.',
        'outcome_metrics' => [['label' => ' Reports ', 'value' => '12'], ['label' => '', 'value' => 'x'], ['label' => 'Blank', 'value' => '']],
    ]);

    expect($updated->title)->toBe('Market sizing for a logistics firm')
        ->and($updated->outcome_metrics)->toBe([['label' => 'Reports', 'value' => '12']]);
});

it('stops the owner editing once the item is no longer a draft, and stops everyone else at any time', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Closed);
    $pending = draftFor($seat, ['status' => ProofStatus::PendingApproval]);
    $draft = ProofOfWork::factory()->create(['user_id' => publicContributor('Other One')->id, 'project_id' => $seat['project']->id]);

    expect(fn () => app(UpdateProofOfWork::class)($pending, $seat['contributor'], ['title' => 'Changed']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateProofOfWork::class)($draft, $seat['contributor'], ['title' => 'Changed']))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateProofOfWork::class)($draft, $seat['owner'], ['title' => 'Changed']))->toThrow(AuthorizationException::class);
});

it('moves draft to pending approval and tells admins, but only with a summary and contribution text', function () {
    Notification::fake();
    $dm = adminUser(AdminRole::DeliveryManager);
    $seat = projectWithCompletedSeat(ProjectStatus::Closed);

    $empty = draftFor($seat, ['summary' => null, 'contribution_text' => null]);
    expect(fn () => app(RequestProofPublication::class)($empty, $seat['contributor']))->toThrow(ActionBlocked::class, 'summary');

    $proof = ProofOfWork::factory()->create(['user_id' => $seat['contributor']->id, 'project_id' => null]);
    $moved = app(RequestProofPublication::class)($proof, $seat['contributor']);

    expect($moved->status)->toBe(ProofStatus::PendingApproval);
    Notification::assertSentTo($dm, ProofOfWorkChanged::class, fn (ProofOfWorkChanged $n) => $n->event === 'submitted');
});

it('lets the owner set visibility on their own item but not on a hidden one or someone elses', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Closed);
    $proof = draftFor($seat);

    expect(app(SetProofVisibility::class)($proof, $seat['contributor'], ProofVisibility::Public)->visibility)->toBe(ProofVisibility::Public);

    $hidden = ProofOfWork::factory()->create(['user_id' => $seat['contributor']->id, 'project_id' => null, 'status' => ProofStatus::Hidden]);

    expect(fn () => app(SetProofVisibility::class)($hidden, $seat['contributor'], ProofVisibility::Public))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SetProofVisibility::class)($proof, publicContributor('Intruder'), ProofVisibility::Private))->toThrow(AuthorizationException::class);
});

it('refuses to publish while the project consent is none, and publishes once the client agrees', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Closed);
    $dm = adminUser(AdminRole::DeliveryManager);
    $proof = draftFor($seat, ['status' => ProofStatus::PendingApproval]);

    expect($seat['project']->showcase_consent)->toBe(ShowcaseConsent::None)
        ->and(fn () => app(PublishProofOfWork::class)($proof, $dm))->toThrow(ActionBlocked::class, 'consent')
        ->and($proof->refresh()->status)->toBe(ProofStatus::PendingApproval)
        ->and($proof->slug)->toBeNull();

    app(SetShowcaseConsent::class)($seat['project'], $seat['owner'], ShowcaseConsent::Anonymous);
    $published = app(PublishProofOfWork::class)($proof->refresh(), $dm);

    expect($published->status)->toBe(ProofStatus::Published)
        ->and($published->slug)->not->toBeNull()
        ->and($published->approved_by)->toBe($dm->id)
        ->and($published->published_at)->not->toBeNull()
        ->and($published->client_display)->toBe(ClientDisplay::Anonymous);
});

it('keeps the slug unchanged when an item is hidden and published again', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Closed, ['showcase_consent' => ShowcaseConsent::Named]);
    $dm = adminUser(AdminRole::DeliveryManager);
    $proof = app(PublishProofOfWork::class)(draftFor($seat, ['status' => ProofStatus::PendingApproval]), $dm);
    $slug = $proof->slug;

    app(HideProofOfWork::class)($proof, $dm, 'Client asked.');
    expect($proof->refresh()->status)->toBe(ProofStatus::Hidden);

    $again = app(PublishProofOfWork::class)($proof, $dm);

    expect($again->slug)->toBe($slug)
        ->and($again->client_display)->toBe(ClientDisplay::Named);
});

it('sends an item back to draft with the admin note, and tells the contributor', function () {
    Notification::fake();
    $seat = projectWithCompletedSeat(ProjectStatus::Closed);
    $dm = adminUser(AdminRole::DeliveryManager);
    $proof = draftFor($seat, ['status' => ProofStatus::PendingApproval]);

    $returned = app(ReturnProofToDraft::class)($proof, $dm, 'Remove the client name.');

    expect($returned->status)->toBe(ProofStatus::Draft);
    Notification::assertSentTo($seat['contributor'], ProofOfWorkChanged::class, fn (ProofOfWorkChanged $n) => $n->event === 'returned' && $n->note === 'Remove the client name.');
});

it('refuses illegal proof-of-work transitions', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Closed, ['showcase_consent' => ShowcaseConsent::Anonymous]);
    $dm = adminUser(AdminRole::DeliveryManager);
    $draft = draftFor($seat);

    expect(fn () => app(PublishProofOfWork::class)($draft, $dm))->toThrow(IllegalTransition::class)
        ->and(fn () => app(HideProofOfWork::class)($draft, $dm))->toThrow(IllegalTransition::class)
        ->and(fn () => app(ReturnProofToDraft::class)($draft, $dm))->toThrow(IllegalTransition::class);
});

it('keeps approval to admins with the approve permission', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Closed, ['showcase_consent' => ShowcaseConsent::Anonymous]);
    $proof = draftFor($seat, ['status' => ProofStatus::PendingApproval]);

    foreach ([$seat['contributor'], $seat['owner'], adminUser(AdminRole::FinanceOps)] as $denied) {
        expect(fn () => app(PublishProofOfWork::class)($proof, $denied))->toThrow(AuthorizationException::class);
    }

    // Community manager holds RUA on proof of work, delivery manager CRUA (spec 5).
    expect(app(PublishProofOfWork::class)($proof, adminUser(AdminRole::CommunityManager))->status)->toBe(ProofStatus::Published);
});

it('follows consent changes for the client display and logs them', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Closed);
    $proof = draftFor($seat);

    app(SetShowcaseConsent::class)($seat['project'], $seat['owner'], ShowcaseConsent::Named);
    expect($proof->refresh()->client_display)->toBe(ClientDisplay::Named);

    app(SetShowcaseConsent::class)($seat['project']->refresh(), $seat['owner'], ShowcaseConsent::None);
    expect($proof->refresh()->client_display)->toBe(ClientDisplay::Anonymous)
        ->and(Activity::query()->where('event', 'showcase_consent')->count())->toBe(2);
});

it('lets only a client owner or manager set consent, from accepted on', function () {
    $seat = projectWithCompletedSeat();
    $manager = clientUser($seat['organization'], OrgRole::Manager);
    $viewer = clientUser($seat['organization'], OrgRole::Viewer);

    expect(app(SetShowcaseConsent::class)($seat['project'], $manager, ShowcaseConsent::Anonymous)->showcase_consent)->toBe(ShowcaseConsent::Anonymous);

    foreach ([$viewer, clientUser(), $seat['contributor'], adminUser(AdminRole::DeliveryManager)] as $denied) {
        expect(fn () => app(SetShowcaseConsent::class)($seat['project'], $denied, ShowcaseConsent::Named))->toThrow(AuthorizationException::class);
    }

    $early = projectWithCompletedSeat(ProjectStatus::Delivered);

    expect(fn () => app(SetShowcaseConsent::class)($early['project'], $early['owner'], ShowcaseConsent::Named))->toThrow(AuthorizationException::class);
});

it('applies the proof-of-work policy rules', function () {
    $seat = projectWithCompletedSeat(ProjectStatus::Closed);
    $proof = draftFor($seat);
    $owner = $seat['contributor'];

    expect(Gate::forUser($owner)->allows('view', $proof))->toBeTrue()
        ->and(Gate::forUser(publicContributor('Peer'))->allows('view', $proof))->toBeFalse()
        ->and(Gate::forUser($seat['owner'])->allows('view', $proof))->toBeFalse()
        ->and(Gate::forUser(adminUser(AdminRole::CommunityManager))->allows('view', $proof))->toBeTrue()
        ->and(Gate::forUser(adminUser(AdminRole::FinanceOps))->allows('view', $proof))->toBeFalse()
        ->and(Gate::forUser(adminUser(AdminRole::DeliveryManager))->allows('delete', $proof))->toBeFalse()
        ->and(Gate::forUser(adminUser(AdminRole::SuperAdmin))->allows('delete', $proof))->toBeTrue();
});
