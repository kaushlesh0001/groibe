<?php

namespace App\Livewire\Contributor;

use App\Actions\Reviews\SubmitClientReview;
use App\Enums\AssignmentStatus;
use App\Enums\FeatureFlag;
use App\Enums\ProjectStatus;
use App\Enums\ReviewDirection;
use App\Exceptions\ActionBlocked;
use App\Models\Assignment;
use App\Models\Project;
use App\Models\ReputationFact;
use App\Models\Review;
use App\Services\FeatureFlags\FeatureFlags;
use App\Services\Reputation\ReputationCalculator;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Reputation (spec 11): my evidence counts and averages, the published client reviews written about me, and the
 * prompts to review the client after a project closes. Evidence only, never a composite score (spec 9).
 */
#[Title('Reputation')]
class Reputation extends Component
{
    /** @var array<int|string, array<string, mixed>> project id => criterion => 1..5 */
    public array $ratings = [];

    /** @var array<int|string, string> project id => comment */
    public array $comments = [];

    public function submitClientReview(int $projectId): void
    {
        $project = Project::query()->findOrFail($projectId);
        $ratings = $this->ratings[$projectId] ?? [];

        $this->resetErrorBag("project-{$projectId}");

        try {
            app(SubmitClientReview::class)($project, Auth::user(), $ratings, $this->comments[$projectId] ?? null);
        } catch (ActionBlocked $e) {
            $this->addError("project-{$projectId}", $e->getMessage());

            return;
        } catch (AuthorizationException) {
            $this->addError("project-{$projectId}", 'You can review this client once the project is closed.');

            return;
        }

        unset($this->ratings[$projectId], $this->comments[$projectId]);
        session()->flash('status', 'Thank you. Your review was sent to Groibe. It is seen by the Groibe team only.');
    }

    public function render()
    {
        $user = Auth::user();

        $facts = ReputationFact::query()->where('user_id', $user->getKey())->first()
            ?? app(ReputationCalculator::class)->forUser($user);

        $received = Review::query()
            ->published()
            ->aboutUser($user)
            ->where('reviews.direction', ReviewDirection::ClientToContributor->value)
            ->with('project:id,title')
            ->latest('reviews.published_at')
            ->limit(20)
            ->get();

        $canReviewClients = app(FeatureFlags::class)->enabled(FeatureFlag::ContributorReviewsOfClients);

        $toReview = collect();

        if ($canReviewClients) {
            $reviewed = Review::query()
                ->where('reviewer_id', $user->getKey())
                ->where('direction', ReviewDirection::ContributorToClient->value)
                ->pluck('project_id');

            $toReview = Project::query()
                ->where('status', ProjectStatus::Closed->value)
                ->whereIn('id', Assignment::query()
                    ->where('user_id', $user->getKey())
                    ->where('status', AssignmentStatus::Completed->value)
                    ->select('project_id'))
                ->whereNotIn('id', $reviewed)
                ->get(['id', 'uuid', 'title', 'status', 'organization_id'])
                ->filter(fn (Project $project): bool => Gate::allows('reviewClient', $project));
        }

        return view('livewire.contributor.reputation', [
            'facts' => $facts,
            'received' => $received,
            'toReview' => $toReview,
            'criteria' => ReviewDirection::ContributorToClient->criteria(),
        ]);
    }
}
