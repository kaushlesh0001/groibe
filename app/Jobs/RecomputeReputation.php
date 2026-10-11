<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\User;
use App\Services\Reputation\ReputationCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Recomputes cached reputation (spec 9): one contributor's facts and/or one organization's stats. Dispatched on
 * project close and whenever a review is published or hidden. Safe to run twice, and to run for a deleted record.
 */
class RecomputeReputation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public ?int $userId = null, public ?int $organizationId = null) {}

    public function handle(ReputationCalculator $calculator): void
    {
        $user = $this->userId === null ? null : User::query()->find($this->userId);
        $organization = $this->organizationId === null ? null : Organization::query()->find($this->organizationId);

        if ($user !== null) {
            $calculator->forUser($user);
        }

        if ($organization !== null) {
            $calculator->forOrganization($organization);
        }
    }
}
