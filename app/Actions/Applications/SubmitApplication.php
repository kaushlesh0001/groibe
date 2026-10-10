<?php

namespace App\Actions\Applications;

use App\Actions\Applications\Concerns\MovesApplication;
use App\Enums\ApplicationStatus;
use App\Enums\FeatureFlag;
use App\Exceptions\ActionBlocked;
use App\Models\Application;
use App\Models\OpenRole;
use App\Models\User;
use App\Notifications\ApplicationUpdated;
use App\Services\FeatureFlags\FeatureFlags;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * An active contributor applies to a role they can see (spec 2.2, 6.5, 11). One application per contributor and role.
 * A withdrawn application can be submitted again with new answers (SCR-019, provisional).
 * Proof-of-work selection (spec 11) arrives with the proof model in Pass 6, so it is not accepted here yet.
 */
class SubmitApplication
{
    use MovesApplication;

    public function __construct(private FeatureFlags $flags, private NotificationDispatcher $dispatcher) {}

    /** @param  array{motivation: string, relevant_experience?: string|null, availability_note?: string|null, expected_amount?: numeric|null}  $data */
    public function __invoke(OpenRole $role, User $contributor, array $data): Application
    {
        Gate::forUser($contributor)->authorize('create', Application::class);

        if ($this->flags->disabled(FeatureFlag::OpenRoles)) {
            throw ActionBlocked::guardFailed('Open roles are not available right now.');
        }

        // A role the contributor cannot see is treated as not found, not as a permission problem.
        if (! $role->isVisibleTo($contributor)) {
            throw new AuthorizationException('This role is not available to you.');
        }

        if (! $role->isOpenForApplications()) {
            throw ActionBlocked::guardFailed('This role is no longer taking applications.');
        }

        $answers = [
            'motivation' => $data['motivation'],
            'relevant_experience' => $data['relevant_experience'] ?? null,
            'availability_note' => $data['availability_note'] ?? null,
            'expected_amount' => $data['expected_amount'] ?? null,
        ];

        $application = DB::transaction(function () use ($role, $contributor, $answers): Application {
            $existing = Application::query()
                ->where('open_role_id', $role->getKey())
                ->forUser($contributor)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                $created = Application::query()->create([
                    ...$answers,
                    'open_role_id' => $role->getKey(),
                    'user_id' => $contributor->getKey(),
                    'status' => ApplicationStatus::Submitted,
                ]);

                activity('application')
                    ->causedBy($contributor)
                    ->performedOn($created)
                    ->event('submitted')
                    ->withProperties(['open_role_id' => $role->getKey()])
                    ->log('Application submitted');

                return $created;
            }

            if ($existing->status !== ApplicationStatus::Withdrawn) {
                throw ActionBlocked::guardFailed('You have already applied to this role.');
            }

            // SCR-019: reapply by reusing the withdrawn row. The earlier answers stay in the activity log.
            return $this->moveApplication($existing, ApplicationStatus::Submitted, $contributor, [
                ...$answers,
                'shortlisted_at' => null,
                'decided_at' => null,
                'decided_by' => null,
                'admin_note' => null,
            ], ['reapplied' => true, 'earlier_motivation' => $existing->motivation]);
        });

        $this->dispatcher->toAdmins('applications.update', new ApplicationUpdated($application, 'received', toAdmin: true));

        return $application;
    }
}
