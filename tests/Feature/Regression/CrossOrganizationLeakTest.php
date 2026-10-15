<?php

use App\Enums\AdminRole;
use App\Enums\MilestoneStatus;
use App\Enums\OnboardingState;
use App\Livewire\Client\Briefs\BriefForm;
use App\Livewire\Client\Deliverables\DeliverableIndex;
use App\Livewire\Client\Projects\ProjectReviews;
use App\Livewire\Client\Projects\ProjectShow;
use App\Livewire\Shared\Messages\Conversation;
use App\Models\Submission;
use App\Models\Thread;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

require_once __DIR__.'/helpers.php';

/*
 * Pass 9 item 7, spec 20: "no cross-organization data leaks, no client access to internal data, no contributor access to other
 * contributors' private data". Two organizations are built with the same shape and different markers (see leakWorld()). The
 * tests then look at every page the other side can reach, from the other side, for a single marker.
 */

beforeEach(function () {
    Storage::fake('local');

    $dm = adminUser(AdminRole::DeliveryManager);
    $this->a = leakWorld('ALPHA', $dm, 1);
    $this->b = leakWorld('BRAVO', $dm, 2);

    // An active contributor with no seat on either project.
    $this->stranger = contributorIn(OnboardingState::Active, [], ['name' => 'Nobody Assigned']);

    /** Every address of world A that has an id in it, as a client or contributor could guess or be given it. */
    $this->aUrls = function (): array {
        $a = $this->a;

        return [
            "/app/client/briefs/{$a['brief']->uuid}",
            "/app/client/briefs/{$a['brief']->uuid}/edit",
            "/app/client/projects/{$a['project']->uuid}",
            "/app/client/projects/{$a['project']->uuid}/reviews",
            "/app/client/projects/{$a['project']->uuid}/sows/{$a['sow']->version}/print",
            "/app/client/projects/{$a['project']->uuid}/sows/{$a['sow']->version}/pdf",
            "/app/client/messages/project/{$a['project']->uuid}",
            "/app/client/messages/{$a['thread']->uuid}",
            signedFileLink($a['briefFile']),
            signedFileLink($a['submission']->getFirstMedia(Submission::FILES)),
        ];
    };

    $this->every = fn (array $world): array => [...$world['tenant'], ...$world['internal']];
});

// ---- Another organization ---------------------------------------------------------------------------------------------

it('refuses another organization every page and file of this one, at every org role', function (string $role) {
    $actor = $this->b['people'][$role];

    foreach (($this->aUrls)() as $url) {
        $response = $this->actingAs($actor)->get($url);

        expect($response->getStatusCode())->toBeIn([403, 404], "{$role} of Bravo got {$response->getStatusCode()} for {$url}")
            ->and(firstMarkerIn((string) $response->getContent(), ($this->every)($this->a)))->toBeNull("{$role} of Bravo saw a marker of Alpha on {$url}");
    }
})->with(['owner', 'manager', 'viewer']);

it('never lists another organization in any overview page', function (string $role) {
    $actor = $this->b['people'][$role];

    foreach (['/app/client', '/app/client/briefs', '/app/client/projects', '/app/client/deliverables', '/app/client/messages', '/notifications', '/settings/privacy'] as $url) {
        $response = $this->actingAs($actor)->get($url);

        expect($response->getStatusCode())->toBe(200, "{$url} for {$role}")
            ->and(firstMarkerIn($response->getContent(), ($this->every)($this->a)))->toBeNull("{$role} of Bravo saw a marker of Alpha on {$url}");
    }

    // The same pages do show the organization's own work, so the empty result above is not an empty page.
    $this->actingAs($actor)->get('/app/client/projects')->assertSee('Project BRAVO');
    $this->actingAs($actor)->get('/app/client/briefs')->assertSee('Brief BRAVO');
})->with(['owner', 'manager', 'viewer']);

it('does not answer to numeric ids, only to the unguessable ones', function () {
    $actor = $this->a['people']['owner'];

    foreach ([
        "/app/client/projects/{$this->a['project']->id}",
        "/app/client/briefs/{$this->a['brief']->id}",
        "/app/client/messages/{$this->a['thread']->id}",
        "/files/{$this->a['briefFile']->id}",
    ] as $url) {
        expect($this->actingAs($actor)->get($url)->getStatusCode())->toBe(404, $url);
    }
});

it('stops every action another organization tries on a record it does not own', function () {
    $stranger = $this->b['people']['owner'];
    $milestone = $this->a['milestone'];

    expectRefused(Livewire::actingAs($stranger)->test(ProjectShow::class, ['project' => $this->a['project']]));
    expectRefused(Livewire::actingAs($stranger)->test(ProjectReviews::class, ['project' => $this->a['project']]));
    expectRefused(Livewire::actingAs($stranger)->test(BriefForm::class, ['brief' => $this->a['brief']]));
    expectRefused(Livewire::actingAs($stranger)->test(Conversation::class, ['thread' => $this->a['thread']]));

    // The deliverables page is theirs, but a milestone id from the other organization is not.
    // A refused call ends that page's session, so each attempt gets its own. Starting a change request only remembers the id;
    // the lookup, and the refusal, happen when it is sent.
    expectRefused(Livewire::actingAs($stranger)->test(DeliverableIndex::class)->call('accept', $milestone->id));
    expectRefused(Livewire::actingAs($stranger)->test(DeliverableIndex::class)
        ->call('startChanges', $milestone->id)->set('changeNote', 'Please change everything')->call('sendChanges'));

    expect($milestone->refresh()->status)->toBe(MilestoneStatus::Shared)
        ->and($milestone->client_accepted_at)->toBeNull();
});

it('does not let the other organization see or open the files', function () {
    foreach (['briefFile' => fn () => $this->a['briefFile'], 'submissionFile' => fn () => $this->a['submission']->getFirstMedia(Submission::FILES)] as $label => $media) {
        $link = signedFileLink($media());

        foreach (['owner', 'manager', 'viewer'] as $role) {
            $this->actingAs($this->b['people'][$role])->get($link)->assertForbidden();
        }

        $this->actingAs($this->b['contributor'])->get($link)->assertForbidden();
        $this->actingAs($this->stranger)->get($link)->assertForbidden();
    }

    auth()->forgetGuards();
    $this->flushSession();
    $this->get(signedFileLink($this->a['briefFile']))->assertRedirect('/login');
});

// ---- Staff-only data, inside the organization it belongs to ----------------------------------------------------------

it('keeps staff notes, payouts and contributor identity out of every page of the organization they belong to', function (string $role) {
    $actor = $this->a['people'][$role];
    $a = $this->a;

    $pages = ['/app/client', '/app/client/briefs', "/app/client/briefs/{$a['brief']->uuid}", '/app/client/projects', "/app/client/projects/{$a['project']->uuid}",
        "/app/client/projects/{$a['project']->uuid}/sows/{$a['sow']->version}/print", '/app/client/deliverables', '/app/client/messages', '/notifications'];

    foreach ($pages as $url) {
        $response = $this->actingAs($actor)->get($url);

        expect($response->getStatusCode())->toBe(200, "{$url} for {$role}")
            ->and(firstMarkerIn($response->getContent(), $a['internal']))->toBeNull("{$role} of Alpha saw staff-only data on {$url}");
    }

    // Pages a viewer may be refused, but must never fill with staff-only data when they do open.
    foreach (["/app/client/messages/{$a['thread']->uuid}", "/app/client/projects/{$a['project']->uuid}/reviews", "/app/client/messages/project/{$a['project']->uuid}"] as $url) {
        $response = $this->actingAs($actor)->get($url, ['Accept' => 'text/html']);

        expect($response->getStatusCode())->toBeIn([200, 302, 403, 404], "{$url} for {$role}")
            ->and(firstMarkerIn((string) $response->getContent(), $a['internal']))->toBeNull("{$role} saw staff-only data on {$url}");
    }
})->with(['owner', 'manager', 'viewer']);

it('shows the organization its own conversation without the internal notes in it', function () {
    $thread = Thread::query()->whereKey($this->a['thread']->id)->firstOrFail();

    $page = $this->actingAs($this->a['people']['owner'])->get("/app/client/messages/{$thread->uuid}");

    $page->assertOk()->assertSee('MESSAGE-ALPHA')->assertDontSee('INTERNAL-MESSAGE-ALPHA');
});

it('opens the organization\'s own files to it, so the refusals above mean something', function () {
    $owner = $this->a['people']['owner'];

    $this->actingAs($owner)->get(signedFileLink($this->a['briefFile']))->assertOk();
    $this->actingAs($owner)->get(signedFileLink($this->a['submission']->getFirstMedia(Submission::FILES)))->assertOk();
});

// ---- Contributors -----------------------------------------------------------------------------------------------------

it('refuses a contributor every project, task, conversation and file they are not seated on', function (string $who) {
    $actor = $who === 'unassigned' ? $this->stranger : $this->b['contributor'];
    $a = $this->a;

    $urls = [
        "/app/contributor/work/projects/{$a['project']->uuid}",
        "/app/contributor/work/tasks/{$a['task']->id}",
        "/app/contributor/messages/{$a['thread']->uuid}",
        "/app/contributor/messages/project/{$a['project']->uuid}",
        signedFileLink($a['submission']->getFirstMedia(Submission::FILES)),
    ];

    foreach ($urls as $url) {
        $response = $this->actingAs($actor)->get($url);

        expect($response->getStatusCode())->toBeIn([403, 404], "{$who} got {$response->getStatusCode()} for {$url}")
            ->and(firstMarkerIn((string) $response->getContent(), ($this->every)($a)))->toBeNull("{$who} saw a marker of Alpha on {$url}");
    }
})->with(['unassigned', 'seated on another project']);

it('shows a contributor only their own work and money, never anyone else\'s', function () {
    foreach (['/app/contributor', '/app/contributor/work', '/app/contributor/payouts', '/app/contributor/reputation', '/app/contributor/portfolio', '/app/contributor/messages', '/notifications'] as $url) {
        $response = $this->actingAs($this->b['contributor'])->get($url);

        expect($response->getStatusCode())->toBe(200, $url)
            ->and(firstMarkerIn($response->getContent(), ($this->every)($this->a)))->toBeNull("Bravo's contributor saw a marker of Alpha on {$url}");
    }

    // Their own paid payout is on their own page, and the staff note beside it is not.
    $this->actingAs($this->b['contributor'])->get('/app/contributor/payouts')
        ->assertSee(number_format(4000000 + 2 * 412300))
        ->assertDontSee('INTERNAL-PAYOUT-NOTE-BRAVO');
});

it('shows a contributor their own work without the staff notes written on it', function () {
    $actor = $this->a['contributor'];

    foreach (['/app/contributor/work', "/app/contributor/work/projects/{$this->a['project']->uuid}", "/app/contributor/work/tasks/{$this->a['task']->id}"] as $url) {
        $response = $this->actingAs($actor)->get($url);

        expect($response->getStatusCode())->toBe(200, $url)
            ->and(firstMarkerIn($response->getContent(), ['INTERNAL-COMMENT-ALPHA', 'INTERNAL-NOTE-ALPHA', 'INTERNAL-MESSAGE-ALPHA', 'INTERNAL-PAYOUT-NOTE-ALPHA']))
            ->toBeNull("the seated contributor saw staff-only data on {$url}");
    }
});

// ---- Roles that do not belong in the other apps ---------------------------------------------------------------------

it('keeps clients out of the contributor app and the admin panel, and contributors out of the client app', function () {
    foreach (['/app/contributor', '/app/contributor/work', '/app/contributor/payouts'] as $url) {
        $this->actingAs($this->a['people']['owner'])->get($url)->assertForbidden();
    }

    foreach (['/app/client', '/app/client/projects', '/app/client/briefs'] as $url) {
        $this->actingAs($this->a['contributor'])->get($url)->assertForbidden();
    }

    foreach ([$this->a['people']['owner'], $this->a['contributor']] as $person) {
        expect($this->actingAs($person)->get('/admin')->getStatusCode())->toBeIn([302, 403, 404]);
    }
});

// ---- The public site ----------------------------------------------------------------------------------------------------

it('puts nothing of a private organization or a private contributor on any public page', function () {
    auth()->forgetGuards();
    $this->flushSession();

    $markers = [...($this->every)($this->a), ...($this->every)($this->b), 'ALPHA', 'BRAVO', $this->a['project']->uuid, $this->b['project']->uuid];

    foreach (['/', '/opportunities', '/contributors', '/organizations', '/work', '/faq', '/how-it-works', '/sitemap.xml', '/skills/anything'] as $url) {
        $response = $this->get($url);

        expect($response->getStatusCode())->toBeIn([200, 404], $url)
            ->and(firstMarkerIn((string) $response->getContent(), $markers))->toBeNull("a private marker is on the public page {$url}");
    }

    $this->get("/organizations/{$this->a['org']->slug}")->assertNotFound();
});
