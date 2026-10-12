<?php

namespace App\Actions\Imports;

use App\Actions\Onboarding\QueueInvites;
use App\Enums\ImportStatus;
use App\Exceptions\IllegalTransition;
use App\Models\Import;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/** Queues invites for the people one finished import created, found through the audit log entries the import wrote (spec 15.1). */
class QueueImportInvites
{
    public function __construct(private readonly QueueInvites $queueInvites) {}

    /** @return array{queued: int, skipped: int} */
    public function __invoke(Import $import, User $actor): array
    {
        Gate::forUser($actor)->authorize('update', $import);

        if ($import->status !== ImportStatus::Completed) {
            throw IllegalTransition::for('import invites', $import->status->value, 'sent');
        }

        $ids = Activity::query()
            ->where('log_name', 'import')
            ->where('event', 'imported')
            ->where('subject_type', (new User)->getMorphClass())
            ->where('properties->import_id', $import->getKey())
            ->pluck('subject_id');

        return ($this->queueInvites)(User::query()->whereKey($ids)->cursor(), $actor);
    }
}
