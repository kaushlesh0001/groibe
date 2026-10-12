<?php

use App\Actions\Agreements\AcceptClientMsa;
use App\Actions\Contributors\AcceptMemberAgreement;
use App\Actions\Projects\SetShowcaseConsent;
use App\Enums\AgreementType;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\ShowcaseConsent;
use App\Models\AgreementAcceptance;
use App\Models\AgreementTemplate;
use App\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Activitylog\Models\Activity;

/*
 * Spec 17.2 asks for "consent timestamps stored". Agreements keep them in `agreement_acceptances`. Showcase consent keeps them in
 * the audit log (SCR-030), because a change of mind has to be traceable, not only the latest value.
 */

describe('showcase consent changes are in the audit log (SCR-030)', function () {
    it('records who changed it, when, and from what to what', function () {
        ['project' => $project, 'owner' => $owner] = projectWithCompletedSeat(ProjectStatus::Accepted);

        $this->travelTo(now()->setTime(10, 30));
        app(SetShowcaseConsent::class)($project, $owner, ShowcaseConsent::Named);

        $entry = Activity::query()->where('event', 'showcase_consent')->sole();

        expect($entry->log_name)->toBe('project')
            ->and($entry->causer_id)->toBe($owner->id)
            ->and($entry->subject_id)->toBe($project->id)
            ->and($entry->properties->all())->toBe(['from' => 'none', 'to' => 'named'])
            ->and($entry->created_at->format('H:i'))->toBe('10:30');
    });

    it('keeps every change, so a change of mind can be traced', function () {
        ['project' => $project, 'owner' => $owner] = projectWithCompletedSeat(ProjectStatus::Accepted);

        app(SetShowcaseConsent::class)($project, $owner, ShowcaseConsent::Named);
        app(SetShowcaseConsent::class)($project->refresh(), $owner, ShowcaseConsent::Anonymous);
        app(SetShowcaseConsent::class)($project->refresh(), $owner, ShowcaseConsent::None);

        $entries = Activity::query()->where('event', 'showcase_consent')->orderBy('id')->get();

        expect($entries->map(fn (Activity $entry) => $entry->properties['from'].'>'.$entry->properties['to'])->all())->toBe(['none>named', 'named>anonymous', 'anonymous>none']);
    });

    it('logs nothing when the choice does not change', function () {
        ['project' => $project, 'owner' => $owner] = projectWithCompletedSeat(ProjectStatus::Accepted);

        app(SetShowcaseConsent::class)($project, $owner, ShowcaseConsent::None);

        expect(Activity::query()->where('event', 'showcase_consent')->count())->toBe(0);
    });

    it('logs nothing when someone who may not change it tries', function () {
        ['project' => $project, 'organization' => $organization] = projectWithCompletedSeat(ProjectStatus::Accepted);
        $viewer = clientUser($organization, OrgRole::Viewer);

        expect(fn () => app(SetShowcaseConsent::class)($project, $viewer, ShowcaseConsent::Named))->toThrow(AuthorizationException::class)
            ->and(fn () => app(SetShowcaseConsent::class)($project, clientUser(), ShowcaseConsent::Named))->toThrow(AuthorizationException::class)
            ->and(Activity::query()->where('event', 'showcase_consent')->count())->toBe(0)
            ->and($project->refresh()->showcase_consent)->toBe(ShowcaseConsent::None);
    });

    it('also logs the value on the project\'s own change entry, so the audit trail shows it twice over', function () {
        ['project' => $project, 'owner' => $owner] = projectWithCompletedSeat(ProjectStatus::Accepted);

        app(SetShowcaseConsent::class)($project, $owner, ShowcaseConsent::Anonymous);

        $change = Activity::query()->where('subject_type', $project->getMorphClass())->where('subject_id', $project->id)->where('event', 'updated')->latest('id')->first();

        expect($change?->properties['attributes']['showcase_consent'] ?? null)->toBe('anonymous');
    });
});

describe('agreement acceptances keep their timestamps', function () {
    beforeEach(fn () => publishAgreements());

    it('stores when, from where, in which browser and which version, for the Member Agreement', function () {
        $user = contributorIn(OnboardingState::ProfileComplete);

        $this->travelTo(now()->setTime(9, 15));
        $acceptance = app(AcceptMemberAgreement::class)($user, '203.0.113.7', 'Firefox/130');

        $template = AgreementTemplate::query()->findOrFail($acceptance->template_id);

        expect($acceptance->accepted_at->format('H:i'))->toBe('09:15')
            ->and($acceptance->ip)->toBe('203.0.113.7')
            ->and($acceptance->user_agent)->toBe('Firefox/130')
            ->and($acceptance->user_id)->toBe($user->id)
            ->and($template->type)->toBe(AgreementType::MemberAgreement)
            ->and($template->version)->toBeInt();
    });

    it('stores the same for the Client MSA, with the organization', function () {
        $organization = Organization::factory()->verified()->create();
        $owner = clientUser($organization, OrgRole::Owner);

        $acceptance = app(AcceptClientMsa::class)($owner, $organization, '203.0.113.8', 'Chrome/130');

        expect($acceptance->accepted_at)->not->toBeNull()->and($acceptance->organization_id)->toBe($organization->id)
            ->and($acceptance->ip)->toBe('203.0.113.8')->and($acceptance->user_agent)->toBe('Chrome/130');
    });

    it('keeps the first acceptance when the same version is accepted again', function () {
        $user = contributorIn(OnboardingState::ProfileComplete);

        $first = app(AcceptMemberAgreement::class)($user, '203.0.113.7', 'Firefox/130');
        $this->travel(2)->days();
        $again = app(AcceptMemberAgreement::class)($user, '198.51.100.1', 'Safari/17');

        expect($again->id)->toBe($first->id)->and(AgreementAcceptance::query()->where('user_id', $user->id)->count())->toBe(1)
            ->and($again->refresh()->ip)->toBe('203.0.113.7');
    });
});
