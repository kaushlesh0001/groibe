<?php

namespace App\Actions\Milestones;

use App\Enums\MilestoneStatus;
use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Task;
use App\Notifications\MilestoneReadyToShare;
use App\Services\Notifications\NotificationDispatcher;

/**
 * The automatic milestone moves of spec 6.9, run after task changes (no authorization of its own):
 * `planned -> in_progress` once any task has started, `in_progress -> in_qa` once every task is
 * `submitted` or `approved`, and `in_qa -> in_progress` when a task leaves those states (SCR-015).
 * Sharing and everything after it stay explicit actions.
 */
class SyncMilestoneStatus
{
    public function __invoke(Milestone $milestone): Milestone
    {
        $milestone = Milestone::query()->lockForUpdate()->findOrFail($milestone->getKey());
        $statuses = Task::query()->where('milestone_id', $milestone->getKey())->pluck('status');

        $finished = [TaskStatus::Submitted, TaskStatus::Approved];

        if ($milestone->status === MilestoneStatus::InQa && ! $statuses->every(fn (TaskStatus $s) => in_array($s, $finished, true))) {
            $this->move($milestone, MilestoneStatus::InProgress);
        }

        if ($milestone->status === MilestoneStatus::Planned && $statuses->contains(fn (TaskStatus $s) => $s !== TaskStatus::Todo)) {
            $this->move($milestone, MilestoneStatus::InProgress);
        }

        if ($milestone->status === MilestoneStatus::InProgress && $statuses->isNotEmpty() && $statuses->every(fn (TaskStatus $s) => in_array($s, $finished, true))) {
            $this->move($milestone, MilestoneStatus::InQa);
        }

        return $milestone;
    }

    private function move(Milestone $milestone, MilestoneStatus $to): void
    {
        $from = $milestone->status;

        $milestone->forceFill(['status' => $to])->save();

        activity('milestone')
            ->performedOn($milestone)
            ->event($to->value)
            ->withProperties(['from' => $from->value, 'to' => $to->value, 'project_id' => $milestone->project_id, 'automatic' => true])
            ->log("Milestone moved from {$from->value} to {$to->value}");

        // The delivery side has something to do once a milestone reaches QA (spec 6.9).
        if ($to === MilestoneStatus::InQa) {
            $milestone->loadMissing('project.deliveryManager');

            app(NotificationDispatcher::class)->toAdmins(
                'milestones.update',
                new MilestoneReadyToShare($milestone, $milestone->project),
                $milestone->project->deliveryManager,
            );
        }
    }
}
