<?php

namespace App\Services\Privacy;

use App\Enums\AssignmentStatus;
use App\Enums\OrgRole;
use App\Enums\PayoutStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProofStatus;
use App\Enums\ProofVisibility;
use App\Enums\ThreadType;
use App\Enums\UserStatus;
use App\Models\Application;
use App\Models\Assignment;
use App\Models\EmailLog;
use App\Models\Message;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\Payout;
use App\Models\Project;
use App\Models\ProofOfWork;
use App\Models\Thread;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Erases a person's personal data and soft-deletes their account (spec 17.2, SCR-028). The row stays, anonymised, so payouts, the
 * work record and the audit trail keep pointing at something real.
 *
 * ERASED: name, email, password, sign-in and two-factor data and every open session; the whole profile and photo; skills; the
 * written answers on applications; the contribution text, summary and metrics of proof of work, which is also hidden and made
 * private; the person's own support conversations; their in-app notifications, notification choices and password-reset tokens;
 * the address on every email log row.
 *
 * KEPT, because it is a record other people or the law rely on: payouts and the audit log (SCR-028); project messages and task
 * comments, which belong to the conversation with the client and the team; submissions and files, which are delivered work;
 * published reviews; agreement acceptances, which are the evidence that a person accepted a version; reputation figures.
 * Everything kept shows the person only as "Deleted user".
 */
class UserAnonymiser
{
    public const NAME = 'Deleted user';

    public const ERASED_TEXT = '[erased at the person\'s request]';

    /**
     * Reasons the account cannot be erased yet. Empty when it can. Work and money in progress must be settled first, because
     * erasing the person mid-way would leave a project team or a payout with nobody to answer for it.
     *
     * @return list<string>
     */
    public function blockers(User $user): array
    {
        $blockers = [];

        if ($user->isAdmin()) {
            return ['Admin accounts are not erased here.'];
        }

        if ($user->isContributor()) {
            $seats = Assignment::query()->where('user_id', $user->getKey())
                ->whereIn('status', [AssignmentStatus::Invited->value, AssignmentStatus::Active->value])->count();

            if ($seats > 0) {
                $blockers[] = "They still have {$seats} project seat(s) that are invited or active. Remove them from the project, or let it finish, first.";
            }

            $payouts = Payout::query()->where('user_id', $user->getKey())
                ->whereIn('status', [PayoutStatus::Planned->value, PayoutStatus::Approved->value])->count();

            if ($payouts > 0) {
                $blockers[] = "They have {$payouts} payout(s) that are planned or approved but not yet paid or cancelled.";
            }
        }

        if ($user->isClient()) {
            foreach ($this->organizationsOnlyThisPersonOwns($user) as $organization) {
                $open = Project::query()->where('organization_id', $organization->getKey())
                    ->whereNotIn('status', [ProjectStatus::Draft->value, ProjectStatus::Closed->value, ProjectStatus::Cancelled->value])->count();

                if ($open > 0) {
                    $blockers[] = "They are the only owner of {$organization->name}, which has {$open} project(s) in progress. Add another owner, or finish the projects, first.";
                }
            }
        }

        return $blockers;
    }

    /** Erases the person. The caller has already checked `blockers()` and runs this inside its own transaction. */
    public function anonymise(User $user): void
    {
        $this->erasePersonalFiles($user);
        $originalEmail = $user->email;

        $this->eraseProfile($user);
        $this->eraseWrittenWork($user);
        $this->eraseSupport($user);
        $this->eraseTraces($user, $originalEmail);

        // Quiet saves: the audit log would otherwise record the old name and email in this row's change entry.
        $user->forceFill([
            'name' => self::NAME,
            'email' => 'deleted-'.strtolower($user->uuid).'@deleted.invalid',
            'password' => null,
            'remember_token' => null,
            'email_verified_at' => null,
            'last_login_at' => null,
            'status' => UserStatus::Suspended,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->saveQuietly();

        $user->delete();
    }

    /** @return iterable<Organization> */
    private function organizationsOnlyThisPersonOwns(User $user): iterable
    {
        return $user->organizations()->get()->filter(function (Organization $organization) use ($user): bool {
            $membership = $organization->getRelation('pivot');

            return $membership->org_role === OrgRole::Owner
                && ! $organization->users()->wherePivot('org_role', OrgRole::Owner->value)->where('users.id', '!=', $user->getKey())->exists();
        });
    }

    private function erasePersonalFiles(User $user): void
    {
        $photo = $user->profile?->photo_path;

        if ($photo !== null) {
            Storage::disk('local')->delete($photo);
        }
    }

    private function eraseProfile(User $user): void
    {
        $user->profile?->forceFill([
            'headline' => null, 'bio' => null, 'phone' => null, 'city' => null, 'state' => null, 'country' => null,
            'photo_path' => null, 'linkedin_url' => null, 'portfolio_url' => null, 'availability' => null, 'hours_per_week' => null,
            'work_modes' => null, 'experience_years' => null, 'languages' => null, 'is_public' => false, 'slug' => null,
        ])->saveQuietly();

        DB::table('skill_user')->where('user_id', $user->getKey())->delete();
    }

    private function eraseWrittenWork(User $user): void
    {
        Application::query()->where('user_id', $user->getKey())->get()->each(
            fn (Application $application) => $application->forceFill([
                'motivation' => self::ERASED_TEXT, 'relevant_experience' => null, 'availability_note' => null, 'proof_of_work_ids' => null,
            ])->saveQuietly(),
        );

        // Hidden and private first, so no public page or search can still show it; then the text goes.
        ProofOfWork::query()->where('user_id', $user->getKey())->get()->each(
            fn (ProofOfWork $proof) => $proof->forceFill([
                'status' => ProofStatus::Hidden, 'visibility' => ProofVisibility::Private,
                'summary' => null, 'contribution_text' => null, 'outcome_metrics' => null,
            ])->saveQuietly(),
        );
    }

    /** The person's own conversations with Groibe support are theirs alone, so they go. Project conversations stay. */
    private function eraseSupport(User $user): void
    {
        $threads = Thread::query()->where('type', ThreadType::Support->value)->where('created_by', $user->getKey());

        Message::query()->whereIn('thread_id', (clone $threads)->select('id'))->where('user_id', $user->getKey())
            ->update(['body' => self::ERASED_TEXT]);

        (clone $threads)->update(['subject' => 'Support request']);
    }

    private function eraseTraces(User $user, string $originalEmail): void
    {
        DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->getKey())->delete();
        NotificationPreference::query()->where('user_id', $user->getKey())->delete();
        EmailLog::query()->where('user_id', $user->getKey())->update(['to' => 'erased']);
        DB::table('password_reset_tokens')->where('email', $originalEmail)->delete();
        DB::table('sessions')->where('user_id', $user->getKey())->delete();
    }
}
