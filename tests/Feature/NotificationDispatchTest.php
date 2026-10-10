<?php

use App\Actions\Briefs\MarkBriefInReview;
use App\Actions\Briefs\RequestBriefInfo;
use App\Actions\Briefs\SubmitBrief;
use App\Actions\Sows\AcceptSow;
use App\Actions\Sows\CreateSow;
use App\Actions\Sows\SendSow;
use App\Enums\AdminRole;
use App\Enums\BriefStatus;
use App\Enums\OrgRole;
use App\Enums\UserStatus;
use App\Filament\Admin\Resources\Briefs\BriefResource;
use App\Filament\Admin\Resources\Projects\ProjectResource;
use App\Models\Brief;
use App\Models\Organization;
use App\Models\Project;
use App\Notifications\BriefStatusChanged;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    publishAgreements();
    $this->org = Organization::factory()->verified()->create();
    $this->owner = acceptClientMsa($this->org);
    $this->manager = clientUser($this->org, OrgRole::Manager);
    $this->viewer = clientUser($this->org, OrgRole::Viewer);
    $this->dm = adminUser(AdminRole::DeliveryManager);
});

/** @return list<Email> */
function sentMail(): array
{
    return collect(app('mailer')->getSymfonyTransport()->messages())
        ->map(fn ($sent) => $sent->getOriginalMessage())->all();
}

it('stores a database notification and sends a mail for a brief submission, with the right link per audience', function () {
    $brief = Brief::factory()->create(['organization_id' => $this->org->id, 'title' => 'Market study']);

    app(SubmitBrief::class)($brief, $this->owner);

    $adminNote = $this->dm->notifications()->sole();
    expect($adminNote->data['title'])->toBe('New brief submitted: Market study')
        ->and($adminNote->data['url'])->toBe(BriefResource::getUrl('edit', ['record' => $brief]))
        ->and($adminNote->data['category'])->toBe('brief');

    $clientNote = $this->owner->notifications()->sole();
    expect($clientNote->data['title'])->toBe('We received your brief: Market study')
        ->and($clientNote->data['url'])->toBe(route('client.briefs.show', $brief));

    $subjects = collect(sentMail())->map(fn ($mail) => $mail->getSubject())->all();
    expect($subjects)->toContain('New brief submitted: Market study')->toContain('We received your brief: Market study');
});

it('notifies owners and managers but not viewers, and skips suspended users', function () {
    $brief = Brief::factory()->create(['organization_id' => $this->org->id]);
    $this->manager->forceFill(['status' => UserStatus::Suspended])->save();

    app(SubmitBrief::class)($brief, $this->owner);

    expect($this->owner->notifications()->count())->toBe(1)
        ->and($this->manager->notifications()->count())->toBe(0)
        ->and($this->viewer->notifications()->count())->toBe(0);
});

it('never notifies other organizations or contributors', function () {
    $outsider = clientUser(Organization::factory()->verified()->create(), OrgRole::Owner);
    $contributor = contributorUser();
    $brief = Brief::factory()->create(['organization_id' => $this->org->id]);

    app(SubmitBrief::class)($brief, $this->owner);

    expect($outsider->notifications()->count())->toBe(0)->and($contributor->notifications()->count())->toBe(0);
});

it('sends the question to the client with the admin note in the mail body', function () {
    $brief = Brief::factory()->status(BriefStatus::InReview)->create(['organization_id' => $this->org->id]);

    app(RequestBriefInfo::class)($brief, $this->dm, 'Which regions do you sell in?');

    $mail = collect(sentMail())->first(fn ($m) => str_contains((string) $m->getSubject(), 'We need more information'));
    expect($mail)->not->toBeNull()->and($mail->getHtmlBody())->toContain('Which regions do you sell in?');
    expect($this->owner->notifications()->sole()->data['body'])->toBe('Which regions do you sell in?');
});

it('notifies the assigned admin only, when there is one, instead of every admin', function () {
    $other = adminUser(AdminRole::DeliveryManager);
    $brief = Brief::factory()->create(['organization_id' => $this->org->id, 'assigned_admin_id' => $other->id]);

    app(SubmitBrief::class)($brief, $this->owner);

    expect($other->notifications()->count())->toBe(1)->and($this->dm->notifications()->count())->toBe(0);
});

it('does not notify community managers or finance about brief submissions', function () {
    $community = adminUser(AdminRole::CommunityManager);
    $finance = adminUser(AdminRole::FinanceOps);
    $brief = Brief::factory()->create(['organization_id' => $this->org->id]);

    app(SubmitBrief::class)($brief, $this->owner);

    expect($community->notifications()->count())->toBe(0)->and($finance->notifications()->count())->toBe(0);
});

it('sends SOW sent and SOW accepted notifications with working links', function () {
    $project = Project::factory()->create(['organization_id' => $this->org->id, 'title' => 'Ops automation', 'delivery_manager_id' => $this->dm->id]);

    $sow = app(SendSow::class)(app(CreateSow::class)($project, $this->dm), $this->dm);

    $sent = $this->owner->notifications()->sole();
    expect($sent->data['title'])->toBe('Statement of Work ready for your review: Ops automation')
        ->and($sent->data['url'])->toBe(route('client.projects.show', $project))
        ->and($this->manager->notifications()->count())->toBe(1)
        ->and($this->viewer->notifications()->count())->toBe(0)
        ->and($this->dm->notifications()->count())->toBe(0);

    app(AcceptSow::class)($sow, $this->owner, true);

    $dmNote = $this->dm->notifications()->sole();
    expect($dmNote->data['title'])->toBe('Statement of Work accepted: Ops automation')
        ->and($dmNote->data['url'])->toBe(ProjectResource::getUrl('edit', ['record' => $project]));
    expect($this->manager->notifications()->count())->toBe(2);

    $subjects = collect(sentMail())->map(fn ($mail) => $mail->getSubject())->all();
    expect($subjects)->toContain('Statement of Work accepted: Ops automation');
});

it('queues notifications instead of sending them inline', function () {
    Queue::fake();
    $brief = Brief::factory()->create(['organization_id' => $this->org->id]);

    app(SubmitBrief::class)($brief, $this->owner);

    Queue::assertPushed(SendQueuedNotifications::class);
    expect($this->owner->notifications()->count())->toBe(0);
});

it('applies the dispatcher channel policy: database and mail, and locked categories stay on', function () {
    expect(config('groibe.notifications.locked_categories'))->toContain('security');

    $notification = new BriefStatusChanged(Brief::factory()->create(['organization_id' => $this->org->id]), BriefStatus::Submitted);
    expect($notification->via($this->owner))->toBe(['database', 'mail']);
});

it('does not leak internal notes in any notification payload', function () {
    $brief = Brief::factory()->status(BriefStatus::Submitted)->create([
        'organization_id' => $this->org->id,
        'internal_notes' => 'SECRET MARGIN NOTE',
    ]);

    app(MarkBriefInReview::class)($brief, $this->dm);

    $payload = json_encode($this->owner->notifications()->get()->pluck('data'));
    $mails = collect(sentMail())->map(fn ($m) => $m->getHtmlBody().$m->getTextBody())->implode(' ');
    expect($payload)->not->toContain('SECRET MARGIN NOTE')->and($mails)->not->toContain('SECRET MARGIN NOTE');
});
