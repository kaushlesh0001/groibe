<?php

namespace App\Actions\Applications;

use App\Actions\Applications\Concerns\MovesApplication;
use App\Enums\ApplicationStatus;
use App\Enums\FeatureFlag;
use App\Enums\ProofStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Application;
use App\Models\OpenRole;
use App\Models\ProofOfWork;
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
 * The applicant may attach up to 5 of their own published proof-of-work items (spec 11); ids that are not theirs are refused.
 */
class SubmitApplication
{
    use MovesApplication;

    private const MAX_PROOF_ITEMS = 5;

    public function __construct(private FeatureFlags $flags, private NotificationDispatcher $dispatcher) {}

    /** @param  array{motivation: string, relevant_experience?: string|null, availability_note?: string|null, expected_amount?: numeric|null, proof_of_work_ids?: list<int|string>|null}  $data */
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
            'proof_of_work_ids' => $this->ownProofIds($contributor, $data['proof_of_work_ids'] ?? []),
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

    /**
     * Only the applicant's own published items may be attached (Pass 6). Anything else, another contributor's
     * item included, is refused rather than silently dropped.
     *
     * @param  list<int|string>  $ids
     * @return list<int>|null
     */
    private function ownProofIds(User $contributor, array $ids): ?array
    {
        $ids = collect($ids)->filter(fn ($id): bool => is_numeric($id))->map(fn ($id): int => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return null;
        }

        if ($ids->count() > self::MAX_PROOF_ITEMS) {
            throw ActionBlocked::guardFailed('You can attach up to '.self::MAX_PROOF_ITEMS.' proof-of-work items.');
        }

        $owned = ProofOfWork::query()
            ->ownedBy($contributor)
            ->where('status', ProofStatus::Published->value)
            ->whereIn('id', $ids)
            ->pluck('id');

        if ($owned->count() !== $ids->count()) {
            throw ActionBlocked::guardFailed('You can only attach your own published proof of work.');
        }

        return $ids->all();
    }
}
