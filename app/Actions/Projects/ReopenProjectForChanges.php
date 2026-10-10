<?php

namespace App\Actions\Projects;

use App\Actions\Projects\Concerns\TransitionsProject;
use App\Enums\ProjectStatus;
use App\Exceptions\IllegalTransition;
use App\Models\Project;
use App\Models\User;

/**
 * delivered -> in_qa, automatically when the client requests changes on a milestone (spec 6.2, SCR-016).
 * Runs on behalf of the client who asked, so it carries no authorization of its own.
 */
class ReopenProjectForChanges
{
    use TransitionsProject;

    public function __invoke(Project $project, ?User $actor): Project
    {
        return $this->moveProject(
            $project,
            ProjectStatus::InQa,
            $actor,
            null,
            properties: ['reason' => 'client_requested_changes'],
            guard: function (Project $locked): void {
                // `active -> in_qa` is a different, admin-only transition; this one is only for delivered projects.
                if ($locked->status !== ProjectStatus::Delivered) {
                    throw IllegalTransition::for('project', $locked->status->value, ProjectStatus::InQa->value);
                }
            },
        );
    }
}
