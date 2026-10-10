<?php

use App\Actions\Agreements\CreateAgreementTemplateVersion;
use App\Actions\Contributors\AcceptMemberAgreement;
use App\Actions\Contributors\ApproveContributor;
use App\Actions\Contributors\CompleteContributorProfile;
use App\Actions\Contributors\ReinstateContributor;
use App\Actions\Contributors\RejectContributor;
use App\Actions\Contributors\SubmitContributorForReview;
use App\Actions\Contributors\SuspendContributor;
use App\Enums\AdminRole;
use App\Enums\AgreementType;
use App\Enums\OnboardingState;
use App\Enums\UserStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\AgreementTemplate;
use App\Models\Skill;
use App\Notifications\ContributorStatusChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    publishAgreements();
    $this->community = adminUser(AdminRole::CommunityManager);
});

function acceptMemberAgreement($user): void
{
    app(AcceptMemberAgreement::class)($user);
}

it('moves a contributor through the whole happy path and keeps users.status in step', function () {
    Notification::fake();
    $contributor = contributorIn(OnboardingState::Registered, ['headline' => 'Analyst', 'bio' => str_repeat('b', 40), 'city' => 'Pune', 'country' => 'India']);
    $contributor->skills()->attach(Skill::factory()->create()->id, ['level' => 'advanced']);
    acceptMemberAgreement($contributor);

    app(CompleteContributorProfile::class)($contributor->profile, $contributor);
    expect($contributor->profile->refresh()->onboarding_state)->toBe(OnboardingState::ProfileComplete)
        ->and($contributor->profile->completed_at)->not->toBeNull();

    app(SubmitContributorForReview::class)($contributor->profile, $contributor);
    expect($contributor->profile->refresh()->onboarding_state)->toBe(OnboardingState::PendingReview)
        ->and($contributor->refresh()->status)->toBe(UserStatus::Pending);

    app(ApproveContributor::class)($contributor->profile, $this->community);
    expect($contributor->profile->refresh()->onboarding_state)->toBe(OnboardingState::Active)
        ->and($contributor->refresh()->status)->toBe(UserStatus::Active);

    app(SuspendContributor::class)($contributor->profile, $this->community, 'Policy breach');
    expect($contributor->profile->refresh()->onboarding_state)->toBe(OnboardingState::Suspended)
        ->and($contributor->refresh()->status)->toBe(UserStatus::Suspended);

    app(ReinstateContributor::class)($contributor->profile, $this->community);
    expect($contributor->profile->refresh()->onboarding_state)->toBe(OnboardingState::Active)
        ->and($contributor->refresh()->status)->toBe(UserStatus::Active);
});

it('rejects a pending contributor and leaves rejected terminal', function () {
    Notification::fake();
    $contributor = contributorIn(OnboardingState::PendingReview);

    app(RejectContributor::class)($contributor->profile, $this->community, 'Not a fit');

    expect($contributor->profile->refresh()->onboarding_state)->toBe(OnboardingState::Rejected)
        ->and($contributor->refresh()->status)->toBe(UserStatus::Pending);

    foreach ([
        fn () => app(ApproveContributor::class)($contributor->profile, $this->community),
        fn () => app(ReinstateContributor::class)($contributor->profile, $this->community),
        fn () => app(SubmitContributorForReview::class)($contributor->profile, $contributor),
        fn () => app(SuspendContributor::class)($contributor->profile, $this->community, 'x'),
    ] as $attempt) {
        expect($attempt)->toThrow(IllegalTransition::class);
    }
});

it('refuses every transition that is not in spec 6.10 with SCR-011', function (OnboardingState $from, string $action) {
    $contributor = contributorIn($from);
    $call = match ($action) {
        'complete' => fn () => app(CompleteContributorProfile::class)($contributor->profile, $contributor),
        'submit' => fn () => app(SubmitContributorForReview::class)($contributor->profile, $contributor),
        'approve' => fn () => app(ApproveContributor::class)($contributor->profile, $this->community),
        'reject' => fn () => app(RejectContributor::class)($contributor->profile, $this->community, 'x'),
        'suspend' => fn () => app(SuspendContributor::class)($contributor->profile, $this->community, 'x'),
        'reinstate' => fn () => app(ReinstateContributor::class)($contributor->profile, $this->community),
    };

    expect($call)->toThrow(IllegalTransition::class);
    expect($contributor->profile->refresh()->onboarding_state)->toBe($from);
})->with([
    'complete from profile_complete' => [OnboardingState::ProfileComplete, 'complete'],
    'complete from active' => [OnboardingState::Active, 'complete'],
    'submit from registered' => [OnboardingState::Registered, 'submit'],
    'submit from pending_review' => [OnboardingState::PendingReview, 'submit'],
    'approve from registered' => [OnboardingState::Registered, 'approve'],
    'approve from profile_complete' => [OnboardingState::ProfileComplete, 'approve'],
    'approve from active' => [OnboardingState::Active, 'approve'],
    'approve from suspended' => [OnboardingState::Suspended, 'approve'],
    'reject from registered' => [OnboardingState::Registered, 'reject'],
    'reject from active' => [OnboardingState::Active, 'reject'],
    'suspend from pending_review' => [OnboardingState::PendingReview, 'suspend'],
    'suspend from suspended' => [OnboardingState::Suspended, 'suspend'],
    'reinstate from active' => [OnboardingState::Active, 'reinstate'],
    'reinstate from rejected' => [OnboardingState::Rejected, 'reinstate'],
    'reinstate from pending_review' => [OnboardingState::PendingReview, 'reinstate'],
]);

it('blocks completing a profile that is missing basics or skills', function () {
    $contributor = contributorIn(OnboardingState::Registered, ['headline' => null, 'bio' => null, 'city' => null]);

    expect(fn () => app(CompleteContributorProfile::class)($contributor->profile, $contributor))
        ->toThrow(ActionBlocked::class, 'Still needed');

    expect($contributor->profile->refresh()->onboarding_state)->toBe(OnboardingState::Registered);
});

it('requires a verified email before pending_review', function () {
    $contributor = completedContributor();
    acceptMemberAgreement($contributor);
    $contributor->forceFill(['email_verified_at' => null])->save();

    expect(fn () => app(SubmitContributorForReview::class)($contributor->profile, $contributor))
        ->toThrow(ActionBlocked::class, 'verify your email');

    expect($contributor->profile->refresh()->onboarding_state)->toBe(OnboardingState::ProfileComplete);
});

it('requires the Member Agreement before pending_review and again before active', function () {
    $contributor = completedContributor();

    expect(fn () => app(SubmitContributorForReview::class)($contributor->profile, $contributor))
        ->toThrow(ActionBlocked::class, 'Member Agreement');

    $pending = contributorIn(OnboardingState::PendingReview);

    expect(fn () => app(ApproveContributor::class)($pending->profile, $this->community))
        ->toThrow(ActionBlocked::class, 'Member Agreement');

    expect($pending->profile->refresh()->onboarding_state)->toBe(OnboardingState::PendingReview)
        ->and($pending->refresh()->status)->toBe(UserStatus::Pending);
});

it('refuses approval when no Member Agreement is published (fail closed)', function () {
    AgreementTemplate::query()->update(['is_active' => false]);
    $pending = contributorIn(OnboardingState::PendingReview);

    expect(fn () => app(ApproveContributor::class)($pending->profile, $this->community))
        ->toThrow(ActionBlocked::class);
});

it('requires acceptance of the current version, not an older one', function () {
    $contributor = completedContributor();
    acceptMemberAgreement($contributor);

    app(CreateAgreementTemplateVersion::class)(
        AgreementTemplate::currentFor(AgreementType::MemberAgreement),
        adminUser(),
        ['title' => 'Member Agreement', 'body_html' => '<p>v2</p>'],
    );

    expect(fn () => app(SubmitContributorForReview::class)($contributor->profile, $contributor))
        ->toThrow(ActionBlocked::class, 'Member Agreement');
});

it('lets only the owner complete and submit their own profile', function () {
    $contributor = completedContributor();
    acceptMemberAgreement($contributor);
    $other = contributorIn(OnboardingState::Registered);

    expect(fn () => app(SubmitContributorForReview::class)($contributor->profile, $other))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SubmitContributorForReview::class)($contributor->profile, $this->community))->toThrow(AuthorizationException::class);
});

it('lets a contributor not approve, reject or suspend anyone including themselves', function () {
    $pending = contributorIn(OnboardingState::PendingReview);

    expect(fn () => app(ApproveContributor::class)($pending->profile, $pending))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RejectContributor::class)($pending->profile, $pending, 'x'))->toThrow(AuthorizationException::class);
});

it('writes one activity entry per transition with from, to and the reason', function () {
    Notification::fake();
    $active = contributorIn(OnboardingState::Active);

    app(SuspendContributor::class)($active->profile, $this->community, 'Missed commitments');

    $entry = Activity::query()->where('log_name', 'contributor')->where('event', 'suspended')->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($this->community->id)
        ->and($entry->subject_id)->toBe($active->profile->id)
        ->and($entry->properties['from'])->toBe('active')
        ->and($entry->properties['to'])->toBe('suspended')
        ->and($entry->properties['reason'])->toBe('Missed commitments');
});

it('notifies the right people and never puts the internal reason in the message', function () {
    Notification::fake();
    $contributor = completedContributor();
    acceptMemberAgreement($contributor);

    app(SubmitContributorForReview::class)($contributor->profile, $contributor);

    Notification::assertSentTo($this->community, ContributorStatusChanged::class, fn ($n) => $n->forAdmins);
    Notification::assertSentTo($contributor, ContributorStatusChanged::class, fn ($n) => $n->to === OnboardingState::PendingReview);

    app(RejectContributor::class)($contributor->profile->refresh(), $this->community, 'Secret internal reason');

    Notification::assertSentTo($contributor, ContributorStatusChanged::class, function ($n) use ($contributor) {
        $payload = json_encode($n->toArray($contributor));

        return $n->to === OnboardingState::Rejected && ! str_contains($payload, 'Secret internal reason');
    });
});

it('does not notify delivery or finance admins about the review queue', function () {
    Notification::fake();
    $delivery = adminUser(AdminRole::DeliveryManager);
    $finance = adminUser(AdminRole::FinanceOps);
    $contributor = completedContributor();
    acceptMemberAgreement($contributor);

    app(SubmitContributorForReview::class)($contributor->profile, $contributor);

    Notification::assertNotSentTo([$delivery, $finance], ContributorStatusChanged::class);
});
