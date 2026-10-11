<?php

namespace App\Livewire\Client\Projects;

use App\Actions\Projects\SetShowcaseConsent;
use App\Actions\Reviews\SubmitContributorReview;
use App\Enums\AssignmentStatus;
use App\Enums\ProjectStatus;
use App\Enums\ReviewDirection;
use App\Enums\ShowcaseConsent;
use App\Exceptions\ActionBlocked;
use App\Models\Assignment;
use App\Models\Project;
use App\Models\Review;
use App\Services\Projects\ClientTeam;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Reviews and showcase consent for one project (spec 9, 12). Owners and managers write reviews and set consent;
 * viewers can read the page. Contributors appear by first name and role, or by role alone when the team is hidden.
 * Reviews are keyed by assignment, so no user id is exposed to the browser.
 */
#[Title('Reviews and showcase')]
class ProjectReviews extends Component
{
    #[Locked]
    public Project $project;

    public string $consent = 'none';

    /** @var array<int|string, array<string, mixed>> assignment id => criterion => 1..5 */
    public array $ratings = [];

    /** @var array<int|string, string> assignment id => comment */
    public array $comments = [];

    public function mount(Project $project): void
    {
        Gate::authorize('view', $project);
        abort_unless(in_array($project->status, [ProjectStatus::Accepted, ProjectStatus::Closed], true), 404);

        $this->project = $project;
        $this->consent = $project->showcase_consent->value;
    }

    public function saveConsent(): void
    {
        $choice = ShowcaseConsent::tryFrom($this->consent);

        if ($choice === null) {
            return;
        }

        try {
            app(SetShowcaseConsent::class)($this->project, Auth::user(), $choice);
            session()->flash('status', 'Your choice was saved.');
        } catch (AuthorizationException) {
            $this->addError('consent', 'Only an owner or manager of your organization can change this.');
        }
    }

    public function submitReview(int $assignmentId): void
    {
        $assignment = Assignment::query()
            ->where('project_id', $this->project->getKey())
            ->where('status', AssignmentStatus::Completed->value)
            ->with('user')
            ->findOrFail($assignmentId);

        $this->resetErrorBag("assignment-{$assignmentId}");

        try {
            app(SubmitContributorReview::class)(
                $this->project,
                Auth::user(),
                $assignment->user,
                $this->ratings[$assignmentId] ?? [],
                $this->comments[$assignmentId] ?? null,
            );
        } catch (ActionBlocked $e) {
            $this->addError("assignment-{$assignmentId}", $e->getMessage());

            return;
        } catch (AuthorizationException) {
            $this->addError("assignment-{$assignmentId}", 'Only an owner or manager of your organization can write reviews.');

            return;
        }

        unset($this->ratings[$assignmentId], $this->comments[$assignmentId]);
        session()->flash('status', 'Thank you. Your review was sent to Groibe and will appear on their profile once checked.');
    }

    public function render()
    {
        // Re-checked on every refresh: membership or the project's state can change while the page is open.
        Gate::authorize('view', $this->project);

        $user = Auth::user();
        $hidden = app(ClientTeam::class)->isHidden($this->project);

        $assignments = Assignment::query()
            ->where('project_id', $this->project->getKey())
            ->where('status', AssignmentStatus::Completed->value)
            ->with('user:id,name')
            ->orderBy('id')
            ->get();

        $written = Review::query()
            ->where('project_id', $this->project->getKey())
            ->where('reviewer_id', $user->getKey())
            ->where('direction', ReviewDirection::ClientToContributor->value)
            ->get()
            ->keyBy('reviewee_id');

        $team = $assignments->map(fn (Assignment $assignment): array => [
            'assignment_id' => $assignment->id,
            'label' => ($hidden ? 'Team member' : ClientTeam::firstName($assignment->user->name)).', '.$assignment->role_title,
            'review' => $written->get($assignment->user_id),
        ]);

        return view('livewire.client.projects.project-reviews', [
            'team' => $team,
            'canManage' => Gate::allows('manageShowcase', $this->project),
            'criteria' => ReviewDirection::ClientToContributor->criteria(),
        ]);
    }
}
