<?php

namespace App\Actions\OpenRoles;

use App\Enums\OpenRoleVisibility;
use App\Enums\ProjectStatus;
use App\Exceptions\ActionBlocked;
use App\Models\OpenRole;
use App\Models\Project;
use App\Models\User;

/**
 * "Publish as Open Role" from a project (prompt pack, Pass 5): creates a draft role linked to the project, with its
 * title, summary, type and skills copied. The admin edits and publishes it like any other role. The client's name is
 * never copied, because roles can be public.
 */
class PublishProjectAsOpenRole
{
    public function __construct(private SaveOpenRole $save) {}

    public function __invoke(Project $project, User $actor): OpenRole
    {
        if (! in_array($project->status, [ProjectStatus::SowAccepted, ProjectStatus::Staffing, ProjectStatus::Active], true)) {
            throw ActionBlocked::guardFailed('A role can be opened from a project once its SOW is accepted and until delivery.');
        }

        return ($this->save)($actor, [
            'project_id' => $project->getKey(),
            'title' => $project->title,
            'summary' => $project->summary,
            'project_type_id' => $project->project_type_id,
            'visibility' => OpenRoleVisibility::MembersOnly,
        ], skillIds: $project->skills()->pluck('skills.id')->all());
    }
}
