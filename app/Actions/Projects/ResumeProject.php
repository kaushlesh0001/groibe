<?php

namespace App\Actions\Projects;

use App\Actions\Projects\Concerns\TransitionsProject;
use App\Enums\ProjectStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Project;
use App\Models\User;
use Closure;

/** on_hold -> the state the project was held from (SCR-008). The stored state is cleared on resume. */
class ResumeProject
{
    use TransitionsProject;

    public function __invoke(Project $project, User $actor): Project
    {
        // The transition re-checks under the row lock that the project is still on hold.
        $target = $project->status_before_hold;

        if ($target === null || ! in_array($target, [ProjectStatus::Staffing, ProjectStatus::Active], true)) {
            return $this->moveProject($project, ProjectStatus::Staffing, $actor, guard: $this->refuse());
        }

        return $this->moveProject($project, $target, $actor, attributes: ['status_before_hold' => null], properties: ['resumed_to' => $target->value]);
    }

    /** @return Closure(Project): void */
    private function refuse(): Closure
    {
        return function (): void {
            throw ActionBlocked::guardFailed('This project has no recorded state to resume to.');
        };
    }
}
