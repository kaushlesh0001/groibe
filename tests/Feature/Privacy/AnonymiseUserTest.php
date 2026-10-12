<?php

use App\Actions\Privacy\CompleteDeletionRequest;
use App\Enums\AdminRole;
use App\Enums\DataRequestStatus;
use App\Enums\OnboardingState;
use App\Enums\OrgRole;
use App\Enums\PayoutStatus;
use App\Enums\ProjectStatus;
use App\Enums\ProofStatus;
use App\Enums\ProofVisibility;
use App\Enums\ThreadType;
use App\Enums\UserStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Application;
use App\Models\Assignment;
use App\Models\DataRequest;
use App\Models\EmailLog;
use App\Models\Message;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\Payout;
use App\Models\Profile;
use App\Models\Project;
use App\Models\ProofOfWork;
use App\Models\Thread;
use App\Models\User;
use App\Services\Privacy\UserAnonymiser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/RichContributor.php';

/** A person whose work is settled, so that erasure is allowed: no open seat and no unpaid payout. */
function settledContributor(): array
{
    $data = richContributor();
    Payout::query()->where('status', PayoutStatus::Planned->value)->delete();

    return $data;
}

describe('what erasure removes', function () {
    it('anonymises the account and soft-deletes it, never hard-deletes it', function () {
        ['person' => $person] = settledContributor();
        $id = $person->id;
        $uuid = strtolower($person->uuid);

        app(UserAnonymiser::class)->anonymise($person);

        $row = (array) DB::table('users')->where('id', $id)->first();

        expect($row)->not->toBeEmpty()
            ->and($row['deleted_at'])->not->toBeNull()
            ->and($row['name'])->toBe('Deleted user')
            ->and($row['email'])->toBe("deleted-{$uuid}@deleted.invalid")
            ->and($row['password'])->toBeNull()
            ->and($row['remember_token'])->toBeNull()
            ->and($row['two_factor_secret'])->toBeNull()
            ->and($row['two_factor_recovery_codes'])->toBeNull()
            ->and($row['two_factor_confirmed_at'])->toBeNull()
            ->and($row['email_verified_at'])->toBeNull()
            ->and($row['last_login_at'])->toBeNull()
            ->and($row['status'])->toBe(UserStatus::Suspended->value)
            ->and(User::query()->whereKey($id)->exists())->toBeFalse()
            ->and(User::withTrashed()->whereKey($id)->exists())->toBeTrue();
    });

    it('gives each erased person a different address, so the unique index never stops a second erasure', function () {
        ['person' => $first] = settledContributor();
        ['person' => $second] = richContributor('-2');
        Payout::query()->where('status', PayoutStatus::Planned->value)->delete();

        app(UserAnonymiser::class)->anonymise($first);
        app(UserAnonymiser::class)->anonymise($second);

        expect(User::withTrashed()->where('name', 'Deleted user')->pluck('email')->unique())->toHaveCount(2);
    });

    it('empties the profile and removes the photo, the slug and the public listing', function () {
        Storage::fake('local');
        Storage::disk('local')->put('profile-photos/me.jpg', 'jpeg');
        $person = publicContributor('Asha Rao', ['phone' => '9800000001', 'bio' => 'Bio', 'photo_path' => 'profile-photos/me.jpg', 'linkedin_url' => 'https://linkedin.com/in/asha']);
        $slug = $person->profile->slug;

        $this->get("/contributors/{$slug}")->assertOk();

        app(UserAnonymiser::class)->anonymise($person);

        $profile = Profile::query()->where('user_id', $person->id)->firstOrFail();

        expect($profile->headline)->toBeNull()->and($profile->bio)->toBeNull()->and($profile->phone)->toBeNull()->and($profile->city)->toBeNull()
            ->and($profile->linkedin_url)->toBeNull()->and($profile->photo_path)->toBeNull()->and($profile->slug)->toBeNull()->and($profile->is_public)->toBeFalse()
            ->and(Storage::disk('local')->exists('profile-photos/me.jpg'))->toBeFalse();

        $this->get("/contributors/{$slug}")->assertNotFound();
        $this->get("/contributors/{$slug}/photo")->assertNotFound();
    });

    it('removes skills, notifications, notification choices, email addresses on the log, reset tokens and sessions', function () {
        ['person' => $person] = settledContributor();
        DB::table('password_reset_tokens')->insert(['email' => $person->email, 'token' => 'abc', 'created_at' => now()]);
        DB::table('sessions')->insert(['id' => 'sess1', 'user_id' => $person->id, 'ip_address' => '203.0.113.9', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => time()]);
        $email = $person->email;

        app(UserAnonymiser::class)->anonymise($person);

        expect(DB::table('skill_user')->where('user_id', $person->id)->count())->toBe(0)
            ->and(DB::table('notifications')->where('notifiable_id', $person->id)->count())->toBe(0)
            ->and(NotificationPreference::query()->where('user_id', $person->id)->count())->toBe(0)
            ->and(DB::table('password_reset_tokens')->where('email', $email)->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $person->id)->count())->toBe(0)
            ->and(EmailLog::query()->where('user_id', $person->id)->pluck('to')->unique()->all())->toBe(['erased']);
    });

    it('erases the written answers on applications and the text of proof of work, and hides the proof', function () {
        ['person' => $person] = settledContributor();
        $proof = ProofOfWork::query()->where('user_id', $person->id)->firstOrFail();
        $proof->forceFill(['status' => ProofStatus::Published, 'visibility' => ProofVisibility::Public, 'slug' => 'pricing-proof-abc', 'summary' => 'A summary', 'outcome_metrics' => ['saved' => 10]])->save();

        app(UserAnonymiser::class)->anonymise($person);

        $application = Application::query()->where('user_id', $person->id)->firstOrFail();
        $proof->refresh();

        expect($application->motivation)->toBe(UserAnonymiser::ERASED_TEXT)->and($application->relevant_experience)->toBeNull()->and($application->availability_note)->toBeNull()
            ->and($proof->status)->toBe(ProofStatus::Hidden)->and($proof->visibility)->toBe(ProofVisibility::Private)
            ->and($proof->contribution_text)->toBeNull()->and($proof->summary)->toBeNull()->and($proof->outcome_metrics)->toBeNull();

        $this->get('/work/pricing-proof-abc')->assertNotFound();
    });

    it('erases the person\'s own support conversations, and leaves what a support thread says about no one else', function () {
        ['person' => $person] = settledContributor();
        $support = Thread::factory()->create(['type' => ThreadType::Support, 'created_by' => $person->id, 'subject' => 'My bank question']);
        Message::factory()->create(['thread_id' => $support->id, 'user_id' => $person->id, 'body' => 'My account number is 1234']);
        $reply = Message::factory()->create(['thread_id' => $support->id, 'user_id' => adminUser(AdminRole::CommunityManager)->id, 'body' => 'Please do not send that.']);

        app(UserAnonymiser::class)->anonymise($person);

        expect($support->refresh()->subject)->toBe('Support request')
            ->and(Message::query()->where('thread_id', $support->id)->where('user_id', $person->id)->value('body'))->toBe(UserAnonymiser::ERASED_TEXT)
            ->and($reply->refresh()->body)->toBe('Please do not send that.');
    });
});

describe('what erasure keeps', function () {
    it('keeps payouts, delivered work and reviews, which now show only "Deleted user"', function () {
        ['person' => $person, 'project' => $project, 'seat' => $seat] = settledContributor();
        $payouts = Payout::query()->where('user_id', $person->id)->count();

        app(UserAnonymiser::class)->anonymise($person);

        expect(Payout::query()->where('user_id', $person->id)->count())->toBe($payouts)->and($payouts)->toBeGreaterThan(0)
            ->and(Payout::query()->where('user_id', $person->id)->first()->amount)->toBe('40000.00')
            ->and(Assignment::query()->find($seat->id)->user_id)->toBe($person->id)
            ->and(Project::query()->find($project->id))->not->toBeNull()
            ->and(Payout::query()->where('user_id', $person->id)->first()->user->name)->toBe('Deleted user');
    });

    it('keeps project messages and the agreements the person accepted', function () {
        ['person' => $person] = settledContributor();
        $messages = Message::query()->where('user_id', $person->id)->where('is_internal', false)->count();
        $acceptances = DB::table('agreement_acceptances')->where('user_id', $person->id)->count();

        app(UserAnonymiser::class)->anonymise($person);

        expect(Message::query()->where('user_id', $person->id)->where('is_internal', false)->count())->toBe($messages)
            ->and(DB::table('agreement_acceptances')->where('user_id', $person->id)->count())->toBe($acceptances)->and($acceptances)->toBe(1);
    });

    it('keeps the audit log, and adds an entry that names no one', function () {
        ['person' => $person] = settledContributor();
        $before = Activity::query()->count();
        $request = DataRequest::factory()->deletion()->status(DataRequestStatus::InProgress)->create(['user_id' => $person->id]);

        app(CompleteDeletionRequest::class)($request, adminUser(), 'Done.');

        $entry = Activity::query()->where('event', 'erased')->sole();

        expect(Activity::query()->count())->toBeGreaterThan($before)
            ->and(json_encode($entry->toArray()))->not->toContain('Asha')->not->toContain('asha@example.com')
            ->and($entry->properties['user_id'])->toBe($person->id);
    });

    it('does not write the old name or email into the audit log while erasing', function () {
        ['person' => $person] = settledContributor();
        $lastBefore = (int) Activity::query()->max('id');

        app(UserAnonymiser::class)->anonymise($person);

        $new = Activity::query()->where('id', '>', $lastBefore)->get();

        expect(json_encode($new->toArray()))->not->toContain('asha@example.com')->not->toContain('Asha Rao');
    });
});

describe('after erasure', function () {
    it('cannot sign in, even with the old address and password', function () {
        $person = contributorIn(OnboardingState::Active, [], ['email' => 'asha@example.com']);
        $person->forceFill(['password' => 'a-long-password-1'])->save();

        app(UserAnonymiser::class)->anonymise($person);

        expect(Auth::attempt(['email' => 'asha@example.com', 'password' => 'a-long-password-1']))->toBeFalse();
    });

    it('lets the old address register again as a new person', function () {
        $person = contributorIn(OnboardingState::Active, [], ['email' => 'asha@example.com']);

        app(UserAnonymiser::class)->anonymise($person);

        expect(User::factory()->contributor()->create(['email' => 'asha@example.com'])->exists)->toBeTrue();
    });

    it('keeps the request itself, pointing at the erased person', function () {
        $request = DataRequest::factory()->deletion()->status(DataRequestStatus::InProgress)->create();

        app(CompleteDeletionRequest::class)($request, adminUser(), 'Done.');

        $request = DataRequest::query()->with('user')->findOrFail($request->id);

        expect($request->status)->toBe(DataRequestStatus::Completed)->and($request->user->name)->toBe('Deleted user')->and($request->user->trashed())->toBeTrue()
            ->and($request->person()->id)->toBe($request->user_id);
    });

    it('sends the person nothing, because the account is gone', function () {
        Notification::fake();
        $request = DataRequest::factory()->deletion()->status(DataRequestStatus::InProgress)->create();

        app(CompleteDeletionRequest::class)($request, adminUser(), null);

        Notification::assertNothingSentTo($request->person());
    });
});

describe('what stops an erasure', function () {
    it('names the open work and unpaid money a contributor still has', function () {
        ['person' => $person, 'project' => $project] = richContributor();
        Assignment::factory()->active()->create(['project_id' => $project->id, 'user_id' => $person->id]);

        $blockers = app(UserAnonymiser::class)->blockers($person);

        expect(implode(' ', $blockers))->toContain('project seat')->toContain('payout');
    });

    it('lets a contributor with only finished seats and paid or cancelled payouts be erased', function () {
        ['person' => $person] = settledContributor();

        expect(app(UserAnonymiser::class)->blockers($person))->toBe([]);
    });

    it('refuses the request, changes nothing and keeps the request in progress', function () {
        ['person' => $person] = richContributor();
        $request = DataRequest::factory()->deletion()->status(DataRequestStatus::InProgress)->create(['user_id' => $person->id]);

        expect(fn () => app(CompleteDeletionRequest::class)($request, adminUser(), null))->toThrow(ActionBlocked::class, 'cannot be erased yet');

        expect($request->refresh()->status)->toBe(DataRequestStatus::InProgress)
            ->and($person->refresh()->name)->toBe('Asha Rao')->and($person->trashed())->toBeFalse()
            ->and(Application::query()->where('user_id', $person->id)->value('motivation'))->toBe('I can do this well.');
    });

    it('refuses a client who is the only owner of an organization with projects in progress, and allows one who is not', function () {
        $organization = Organization::factory()->verified()->create(['name' => 'Acme Foods']);
        $owner = clientUser($organization, OrgRole::Owner);
        $project = Project::factory()->status(ProjectStatus::Active)->create(['organization_id' => $organization->id]);

        expect(implode(' ', app(UserAnonymiser::class)->blockers($owner)))->toContain('only owner of Acme Foods');

        $second = clientUser($organization, OrgRole::Owner);
        expect(app(UserAnonymiser::class)->blockers($owner))->toBe([]);

        $project->forceFill(['status' => ProjectStatus::Closed])->save();
        expect(app(UserAnonymiser::class)->blockers($second))->toBe([]);
    });

    it('does not stop a client who is not an owner, or whose organization has only finished or draft projects', function () {
        $organization = Organization::factory()->verified()->create();
        $owner = clientUser($organization, OrgRole::Owner);
        $manager = clientUser($organization, OrgRole::Manager);
        Project::factory()->status(ProjectStatus::Active)->create(['organization_id' => $organization->id]);

        expect(app(UserAnonymiser::class)->blockers($manager))->toBe([]);

        $solo = Organization::factory()->verified()->create();
        $soloOwner = clientUser($solo, OrgRole::Owner);
        Project::factory()->status(ProjectStatus::Draft)->create(['organization_id' => $solo->id]);
        Project::factory()->status(ProjectStatus::Cancelled)->create(['organization_id' => $solo->id]);

        expect(app(UserAnonymiser::class)->blockers($soloOwner))->toBe([]);
    });

    it('never erases an admin', function () {
        expect(app(UserAnonymiser::class)->blockers(adminUser()))->toBe(['Admin accounts are not erased here.']);
    });

    it('rolls everything back if the erasure fails half way', function () {
        ['person' => $person] = settledContributor();
        $request = DataRequest::factory()->deletion()->status(DataRequestStatus::InProgress)->create(['user_id' => $person->id]);

        // Make the last step fail: a database trigger is overkill, so break the audit write by faking a throwing listener.
        Event::listen('eloquent.deleting: '.User::class, fn () => throw new RuntimeException('disk full'));

        expect(fn () => app(CompleteDeletionRequest::class)($request, adminUser(), null))->toThrow(RuntimeException::class);

        expect($request->refresh()->status)->toBe(DataRequestStatus::InProgress)
            ->and(User::query()->whereKey($person->id)->value('name'))->toBe('Asha Rao')
            ->and(Application::query()->where('user_id', $person->id)->value('motivation'))->toBe('I can do this well.')
            ->and(DB::table('skill_user')->where('user_id', $person->id)->count())->toBe(1);
    });
});
