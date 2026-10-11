<?php

use App\Actions\Assignments\RemoveAssignment;
use App\Enums\AdminRole;
use App\Enums\FeatureFlag;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Assignment;
use App\Models\Milestone;
use App\Models\OpenRole;
use App\Models\Project;
use App\Models\Task;
use App\Services\FeatureFlags\FeatureFlags;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
 * Livewire sends follow-up actions and refreshes to its own update endpoint, and re-applies only a short list
 * of the page's middleware there. Role and active-contributor checks must be on that list, or a contributor
 * who is suspended while a page is open could keep using it.
 */

/**
 * The snapshot of the page's own component. The layout also carries chrome components such as the notification bell, which come
 * first in the HTML, so taking the first snapshot would refresh the bell and prove nothing about the page.
 */
function pageSnapshot(string $html): string
{
    preg_match_all('/wire:snapshot="([^"]+)"[^>]*wire:name="([^"]+)"/', $html, $matches, PREG_SET_ORDER);

    $page = collect($matches)->first(fn (array $match): bool => $match[2] !== 'notifications.bell');

    expect($page)->not->toBeNull('the page has a component of its own');

    return html_entity_decode($page[1]);
}

/** Opens a page over HTTP and posts a refresh of its own component to Livewire's update endpoint. */
function refreshOpenPage(string $url): TestResponse
{
    $html = test()->get($url)->assertOk()->getContent();

    $snapshot = pageSnapshot($html);

    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(Livewire::getUpdateUri(), [
        'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]],
    ]);
}

beforeEach(function () {
    $this->project = Project::factory()->status(ProjectStatus::Active)->create();
    $this->me = contributorIn(OnboardingState::Active);
    $seat = Assignment::factory()->active()->create(['project_id' => $this->project->id, 'user_id' => $this->me->id]);
    $this->task = Task::factory()->create(['milestone_id' => Milestone::factory()->create(['project_id' => $this->project->id])->id, 'assignment_id' => $seat->id]);
});

it('keeps serving a refresh to an active contributor', function () {
    asUser($this->me);

    refreshOpenPage(route('contributor.work.project', $this->project))->assertOk();
    refreshOpenPage(route('contributor.work.task', $this->task))->assertOk();
});

it('refuses a refresh once the contributor has been suspended or is no longer active', function (string $page) {
    asUser($this->me);
    $url = $page === 'board' ? route('contributor.work.project', $this->project) : route('contributor.work.task', $this->task);

    $html = $this->get($url)->assertOk()->getContent();
    $snapshot = pageSnapshot($html);

    $this->me->forceFill(['status' => UserStatus::Suspended])->save();
    $this->me->profile->forceFill(['onboarding_state' => OnboardingState::Suspended])->save();

    $this->withHeaders(['X-Livewire' => 'true'])
        ->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]]])
        ->assertForbidden();
})->with(['board', 'task']);

it('refuses a refresh of a client page by someone who is not a client', function () {
    $owner = clientUser($this->project->organization, OrgRole::Owner);
    asUser($owner);

    $html = $this->get(route('client.deliverables'))->assertOk()->getContent();
    $snapshot = pageSnapshot($html);

    // The account is switched to a contributor mid-session (for example by an admin).
    $owner->forceFill(['role' => UserRole::Contributor])->save();

    $this->withHeaders(['X-Livewire' => 'true'])
        ->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]]])
        ->assertForbidden();
});

/*
 * A refresh must also re-check access to the record. Access can be withdrawn while a page is open: a
 * contributor taken off a task, or a client removed from the organization.
 */

it('stops showing a task to a contributor who has been taken off it', function () {
    asUser($this->me);
    $url = route('contributor.work.task', $this->task);

    $html = $this->get($url)->assertOk()->getContent();
    $snapshot = pageSnapshot($html);

    // An admin removes the contributor: the task returns to unassigned.
    app(RemoveAssignment::class)($this->task->assignment, adminUser(AdminRole::DeliveryManager), 'Left the project');

    $this->withHeaders(['X-Livewire' => 'true'])
        ->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]]])
        ->assertNotFound();
});

it('stops showing a project to a client who has been removed from the organization', function () {
    $project = Project::factory()->status(ProjectStatus::Active)->create();
    $client = clientUser($project->organization, OrgRole::Owner);
    asUser($client);
    $url = route('client.projects.show', $project);

    $html = $this->get($url)->assertOk()->getContent();
    $snapshot = pageSnapshot($html);

    $project->organization->users()->detach($client->id);

    // A new request loads the user afresh (the membership lookup is memoized per user object).
    asUser($client->fresh());

    $this->withHeaders(['X-Livewire' => 'true'])
        ->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]]])
        ->assertForbidden();
});

it('stops an open Discover or My Applications page once the open_roles flag is switched off', function () {
    $contributor = contributorIn(OnboardingState::Active);
    OpenRole::factory()->published()->create(['title' => 'Still listed?']);
    asUser($contributor);

    foreach (['/app/contributor/discover', '/app/contributor/applications'] as $url) {
        app(FeatureFlags::class)->set(FeatureFlag::OpenRoles, true);
        app(FeatureFlags::class)->flush();

        $html = $this->get($url)->assertOk()->getContent();
        $snapshot = pageSnapshot($html);

        app(FeatureFlags::class)->set(FeatureFlag::OpenRoles, false);
        app(FeatureFlags::class)->flush();

        $this->withHeaders(['X-Livewire' => 'true'])
            ->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]]])
            ->assertNotFound();
    }
});
