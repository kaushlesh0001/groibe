<?php

use App\Actions\Agreements\CreateAgreementTemplateVersion;
use App\Actions\Briefs\ConvertBriefToProject;
use App\Actions\Briefs\DeclineBrief;
use App\Actions\Briefs\MarkBriefInReview;
use App\Actions\Briefs\MarkBriefScoped;
use App\Actions\Briefs\RequestBriefInfo;
use App\Actions\Briefs\ResumeBrief;
use App\Actions\Briefs\SubmitBrief;
use App\Enums\AdminRole;
use App\Enums\AgreementType;
use App\Enums\BriefStatus;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\AgreementTemplate;
use App\Models\Brief;
use App\Models\Organization;
use App\Models\Skill;
use App\Notifications\BriefStatusChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    publishAgreements();
    $this->org = Organization::factory()->verified()->create();
    $this->owner = acceptClientMsa($this->org);
    $this->dm = adminUser(AdminRole::DeliveryManager);
});

function briefIn(BriefStatus $status, ?Organization $org = null): Brief
{
    return Brief::factory()->status($status)->create(['organization_id' => ($org ?? test()->org)->id]);
}

it('submits a draft brief, stamps the submitter and notifies admins and the client', function () {
    $brief = briefIn(BriefStatus::Draft);

    $submitted = app(SubmitBrief::class)($brief, $this->owner);

    expect($submitted->status)->toBe(BriefStatus::Submitted)
        ->and($submitted->submitted_by)->toBe($this->owner->id)
        ->and($submitted->submitted_at)->not->toBeNull();

    Notification::assertSentTo($this->dm, BriefStatusChanged::class, fn ($n) => $n->forAdmins === true);
    Notification::assertSentTo($this->owner, BriefStatusChanged::class, fn ($n) => $n->forAdmins === false);
    expect(Activity::query()->where('log_name', 'brief')->where('event', 'submitted')->exists())->toBeTrue();
});

it('walks the full happy path to converted', function () {
    $brief = briefIn(BriefStatus::Draft);
    $skill = Skill::factory()->create();
    $brief->skills()->attach($skill);

    app(SubmitBrief::class)($brief, $this->owner);
    app(MarkBriefInReview::class)($brief, $this->dm);
    app(RequestBriefInfo::class)($brief, $this->dm, 'What is the data source?');
    expect($brief->refresh()->status)->toBe(BriefStatus::NeedsInfo)
        ->and($brief->needs_info_note)->toBe('What is the data source?');

    app(ResumeBrief::class)($brief, $this->owner);
    app(MarkBriefScoped::class)($brief, $this->dm);

    $project = app(ConvertBriefToProject::class)($brief, $this->dm);

    expect($brief->refresh()->status)->toBe(BriefStatus::Converted)
        ->and($brief->converted_project_id)->toBe($project->id)
        ->and($project->brief_id)->toBe($brief->id)
        ->and($project->status)->toBe(ProjectStatus::Draft)
        ->and($project->organization_id)->toBe($this->org->id)
        ->and($project->code)->toMatch('/^GRB-\d{4}-\d{4}$/')
        ->and($project->skills()->pluck('skills.id')->all())->toBe([$skill->id]);
});

it('can decline a brief that is in review and keeps the reason', function () {
    $brief = briefIn(BriefStatus::InReview);

    app(DeclineBrief::class)($brief, $this->dm, 'Out of scope for now');

    expect($brief->refresh()->status)->toBe(BriefStatus::Declined);
    Notification::assertSentTo($this->owner, BriefStatusChanged::class, fn ($n) => $n->note === 'Out of scope for now');
});

it('refuses convert unless the brief is scoped (SCR-005)', function () {
    $brief = briefIn(BriefStatus::InReview);

    expect(fn () => app(ConvertBriefToProject::class)($brief, $this->dm))->toThrow(IllegalTransition::class);
    expect($brief->refresh()->status)->toBe(BriefStatus::InReview);
});

it('rejects every illegal brief transition', function (string $action, BriefStatus $from, array $args, string $who) {
    $brief = briefIn($from);
    $actor = $who === 'owner' ? $this->owner : $this->dm;

    expect(fn () => app($action)($brief, $actor, ...$args))->toThrow(IllegalTransition::class);
})->with([
    'submit a submitted brief' => [SubmitBrief::class, BriefStatus::Submitted, [], 'owner'],
    'in review from draft' => [MarkBriefInReview::class, BriefStatus::Draft, [], 'dm'],
    'request info from submitted' => [RequestBriefInfo::class, BriefStatus::Submitted, ['question'], 'dm'],
    'resume when in review' => [ResumeBrief::class, BriefStatus::InReview, [], 'owner'],
    'scope from needs_info' => [MarkBriefScoped::class, BriefStatus::NeedsInfo, [], 'dm'],
    'decline from scoped' => [DeclineBrief::class, BriefStatus::Scoped, ['no'], 'dm'],
    'decline a declined brief' => [DeclineBrief::class, BriefStatus::Declined, ['no'], 'dm'],
    'convert a converted brief' => [ConvertBriefToProject::class, BriefStatus::Converted, [], 'dm'],
]);

it('requires a question to request info', function () {
    $brief = briefIn(BriefStatus::InReview);

    expect(fn () => app(RequestBriefInfo::class)($brief, $this->dm, '  '))->toThrow(ActionBlocked::class);
});

it('blocks submit until the organization is verified or overridden', function () {
    $org = Organization::factory()->create(); // pending
    $owner = acceptClientMsa($org);
    $brief = briefIn(BriefStatus::Draft, $org);

    expect(fn () => app(SubmitBrief::class)($brief, $owner))->toThrow(ActionBlocked::class, 'verified');
    expect($brief->refresh()->status)->toBe(BriefStatus::Draft);

    $org->update(['can_submit_briefs' => true]);
    expect(app(SubmitBrief::class)($brief, $owner)->status)->toBe(BriefStatus::Submitted);
});

it('blocks submit until the owner has accepted the Client MSA', function () {
    $org = Organization::factory()->verified()->create();
    $owner = clientUser($org, OrgRole::Owner);
    $brief = briefIn(BriefStatus::Draft, $org);

    expect(fn () => app(SubmitBrief::class)($brief, $owner))->toThrow(ActionBlocked::class, 'Client MSA');
});

it('blocks submit when a manager accepted but the owner did not', function () {
    $org = Organization::factory()->verified()->create();
    clientUser($org, OrgRole::Owner);
    $manager = clientUser($org, OrgRole::Manager);
    $brief = briefIn(BriefStatus::Draft, $org);

    expect(fn () => app(SubmitBrief::class)($brief, $manager))->toThrow(ActionBlocked::class);
});

it('fails closed when no Client MSA is published', function () {
    AgreementTemplate::query()->update(['is_active' => false]);
    $brief = briefIn(BriefStatus::Draft);

    expect(fn () => app(SubmitBrief::class)($brief, $this->owner))->toThrow(ActionBlocked::class);
});

it('requires acceptance of the new MSA version after a new version is published', function () {
    $current = AgreementTemplate::currentFor(AgreementType::ClientMsa);
    app(CreateAgreementTemplateVersion::class)($current, adminUser(), ['title' => 'MSA v2', 'body_html' => '<p>v2</p>']);

    expect(fn () => app(SubmitBrief::class)(briefIn(BriefStatus::Draft), $this->owner))->toThrow(ActionBlocked::class);
});

it('rejects an incomplete brief', function () {
    $brief = Brief::factory()->create(['organization_id' => $this->org->id, 'problem' => null]);

    expect(fn () => app(SubmitBrief::class)($brief, $this->owner))->toThrow(ActionBlocked::class);
});

it('denies actions to the wrong people', function () {
    $other = clientUser(Organization::factory()->verified()->create());
    $viewer = clientUser($this->org, OrgRole::Viewer);
    $community = adminUser(AdminRole::CommunityManager);
    $draft = briefIn(BriefStatus::Draft);

    expect(fn () => app(SubmitBrief::class)($draft, $other))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SubmitBrief::class)($draft, $viewer))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MarkBriefInReview::class)(briefIn(BriefStatus::Submitted), $this->owner))->toThrow(AuthorizationException::class)
        ->and(fn () => app(MarkBriefInReview::class)(briefIn(BriefStatus::Submitted), $community))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ConvertBriefToProject::class)(briefIn(BriefStatus::Scoped), $this->owner))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ResumeBrief::class)(briefIn(BriefStatus::NeedsInfo), $other))->toThrow(AuthorizationException::class);
});

it('lets the owner or an admin resume a brief in needs_info and notifies the other side', function () {
    $byClient = briefIn(BriefStatus::NeedsInfo);
    app(ResumeBrief::class)($byClient, $this->owner);
    Notification::assertSentTo($this->dm, BriefStatusChanged::class, fn ($n) => $n->forAdmins === true);

    $byAdmin = briefIn(BriefStatus::NeedsInfo);
    app(ResumeBrief::class)($byAdmin, $this->dm);
    Notification::assertSentTo($this->owner, BriefStatusChanged::class, fn ($n) => $n->forAdmins === false && $n->brief->is($byAdmin));
});

it('lets clients edit a brief only in draft and needs_info', function (BriefStatus $status, bool $editable) {
    $brief = briefIn($status);

    expect($this->owner->can('update', $brief))->toBe($editable);
})->with([
    [BriefStatus::Draft, true],
    [BriefStatus::NeedsInfo, true],
    [BriefStatus::Submitted, false],
    [BriefStatus::InReview, false],
    [BriefStatus::Scoped, false],
    [BriefStatus::Converted, false],
    [BriefStatus::Declined, false],
]);
