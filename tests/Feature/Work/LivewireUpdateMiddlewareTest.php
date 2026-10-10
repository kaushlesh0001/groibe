<?php

use App\Actions\Assignments\RemoveAssignment;
use App\Enums\AdminRole;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Assignment;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
 * Livewire sends follow-up actions and refreshes to its own update endpoint, and re-applies only a short list
 * of the page's middleware there. Role and active-contributor checks must be on that list, or a contributor
 * who is suspended while a page is open could keep using it.
 */

/** Opens a page over HTTP and posts a refresh of its first component to Livewire's update endpoint. */
function refreshOpenPage(string $url): TestResponse
{
    $html = test()->get($url)->assertOk()->getContent();

    preg_match('/wire:snapshot="([^"]+)"/', $html, $match);
    $snapshot = html_entity_decode($match[1]);

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
    preg_match('/wire:snapshot="([^"]+)"/', $html, $match);
    $snapshot = html_entity_decode($match[1]);

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
    preg_match('/wire:snapshot="([^"]+)"/', $html, $match);
    $snapshot = html_entity_decode($match[1]);

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
    preg_match('/wire:snapshot="([^"]+)"/', $html, $match);
    $snapshot = html_entity_decode($match[1]);

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
    preg_match('/wire:snapshot="([^"]+)"/', $html, $match);
    $snapshot = html_entity_decode($match[1]);

    $project->organization->users()->detach($client->id);

    // A new request loads the user afresh (the membership lookup is memoized per user object).
    asUser($client->fresh());

    $this->withHeaders(['X-Livewire' => 'true'])
        ->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => '$refresh', 'params' => []]]]]])
        ->assertForbidden();
});
