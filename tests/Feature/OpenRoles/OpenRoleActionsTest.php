<?php

use App\Actions\OpenRoles\ArchiveOpenRole;
use App\Actions\OpenRoles\CloseOpenRole;
use App\Actions\OpenRoles\InviteToOpenRole;
use App\Actions\OpenRoles\PublishOpenRole;
use App\Actions\OpenRoles\PublishProjectAsOpenRole;
use App\Actions\OpenRoles\RemoveOpenRoleInvite;
use App\Actions\OpenRoles\SaveOpenRole;
use App\Enums\AdminRole;
use App\Enums\OnboardingState;
use App\Enums\OpenRoleStatus;
use App\Enums\OpenRoleVisibility;
use App\Enums\ProjectStatus;
use App\Exceptions\ActionBlocked;
use App\Exceptions\IllegalTransition;
use App\Models\OpenRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Skill;
use App\Notifications\OpenRoleInvited;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->dm = adminUser(AdminRole::DeliveryManager);
    $this->cm = adminUser(AdminRole::CommunityManager);
    $this->finance = adminUser(AdminRole::FinanceOps);
    $this->contributor = contributorIn(OnboardingState::Active);
});

function roleData(array $overrides = []): array
{
    return [
        'title' => 'Market research analyst',
        'summary' => 'Help us size a market.',
        'description' => 'Desk research and interviews.',
        'visibility' => OpenRoleVisibility::MembersOnly,
        'positions_needed' => 1,
        ...$overrides,
    ];
}

describe('saving', function () {
    it('creates a draft with a generated slug, the actor as creator, skills and an audit entry', function () {
        $skill = Skill::factory()->create();

        $role = app(SaveOpenRole::class)($this->cm, roleData(), null, [$skill->id]);

        expect($role->status)->toBe(OpenRoleStatus::Draft)
            ->and($role->slug)->toStartWith('market-research-analyst-')
            ->and($role->created_by)->toBe($this->cm->id)
            ->and($role->uuid)->toHaveLength(26)
            ->and($role->skills->pluck('id')->all())->toBe([$skill->id])
            ->and(Activity::query()->where('log_name', 'open_role')->where('event', 'created')->count())->toBe(1);
    });

    it('lets delivery managers, community managers and super admins write, and nobody else', function () {
        foreach ([$this->dm, $this->cm, adminUser()] as $admin) {
            expect(app(SaveOpenRole::class)($admin, roleData()))->toBeInstanceOf(OpenRole::class);
        }

        foreach ([$this->finance, $this->contributor, clientUser()] as $denied) {
            expect(fn () => app(SaveOpenRole::class)($denied, roleData()))->toThrow(AuthorizationException::class);
        }
    });

    it('edits drafts and published roles but never keeps a changed slug', function () {
        $role = app(SaveOpenRole::class)($this->dm, roleData());
        $slug = $role->slug;

        app(PublishOpenRole::class)($role, $this->dm);
        $updated = app(SaveOpenRole::class)($this->dm, roleData(['title' => 'A new title', 'slug' => 'hacked']), $role);

        expect($updated->title)->toBe('A new title')->and($updated->slug)->toBe($slug);
    });

    it('refuses to edit closed, filled or archived roles', function (OpenRoleStatus $status) {
        $role = OpenRole::factory()->status($status)->create();

        expect(fn () => app(SaveOpenRole::class)($this->dm, roleData(), $role))->toThrow(ActionBlocked::class);
    })->with([OpenRoleStatus::Closed, OpenRoleStatus::Filled, OpenRoleStatus::Archived]);
});

describe('state machine', function () {
    it('publishes a complete draft and stamps the date', function () {
        $role = OpenRole::factory()->create();

        $published = app(PublishOpenRole::class)($role, $this->cm);

        expect($published->status)->toBe(OpenRoleStatus::Published)->and($published->published_at)->not->toBeNull();
    });

    it('will not publish a role without a summary or description', function () {
        $role = OpenRole::factory()->create(['summary' => null, 'description' => '']);

        expect(fn () => app(PublishOpenRole::class)($role, $this->dm))->toThrow(ActionBlocked::class, 'a summary, a description')
            ->and($role->fresh()->status)->toBe(OpenRoleStatus::Draft);
    });

    it('closes and archives only a published role', function () {
        $closed = app(CloseOpenRole::class)(OpenRole::factory()->published()->create(), $this->dm);
        $archived = app(ArchiveOpenRole::class)(OpenRole::factory()->published()->create(), $this->dm);

        expect($closed->status)->toBe(OpenRoleStatus::Closed)->and($closed->closed_at)->not->toBeNull()
            ->and($archived->status)->toBe(OpenRoleStatus::Archived);

        foreach ([OpenRoleStatus::Draft, OpenRoleStatus::Closed, OpenRoleStatus::Filled, OpenRoleStatus::Archived] as $status) {
            $role = OpenRole::factory()->status($status)->create();

            expect(fn () => app(CloseOpenRole::class)($role, $this->dm))->toThrow(IllegalTransition::class)
                ->and(fn () => app(ArchiveOpenRole::class)($role, $this->dm))->toThrow(IllegalTransition::class);
        }
    });

    it('never publishes a role that is not a draft', function (OpenRoleStatus $status) {
        $role = OpenRole::factory()->status($status)->create();

        expect(fn () => app(PublishOpenRole::class)($role, $this->dm))->toThrow(IllegalTransition::class);
    })->with([OpenRoleStatus::Published, OpenRoleStatus::Closed, OpenRoleStatus::Filled, OpenRoleStatus::Archived]);

    it('denies the transitions to finance, contributors and clients', function () {
        $draft = OpenRole::factory()->create();
        $live = OpenRole::factory()->published()->create();

        foreach ([$this->finance, $this->contributor, clientUser()] as $denied) {
            expect(fn () => app(PublishOpenRole::class)($draft, $denied))->toThrow(AuthorizationException::class)
                ->and(fn () => app(CloseOpenRole::class)($live, $denied))->toThrow(AuthorizationException::class)
                ->and(fn () => app(ArchiveOpenRole::class)($live, $denied))->toThrow(AuthorizationException::class);
        }
    });

    it('writes an audit entry for every move', function () {
        $role = OpenRole::factory()->create();
        app(PublishOpenRole::class)($role, $this->dm);
        app(CloseOpenRole::class)($role, $this->dm);

        expect(Activity::query()->where('log_name', 'open_role')->pluck('event')->all())->toEqualCanonicalizing(['published', 'closed']);
    });
});

describe('invite list (SCR-018)', function () {
    beforeEach(function () {
        $this->role = OpenRole::factory()->published()->inviteOnly()->create();
    });

    it('adds an active contributor once, logs it and tells them when the role is live', function () {
        Notification::fake();

        expect(app(InviteToOpenRole::class)($this->role, $this->contributor, $this->dm))->toBeTrue()
            ->and(app(InviteToOpenRole::class)($this->role, $this->contributor, $this->dm))->toBeFalse()
            ->and($this->role->invitedUsers()->count())->toBe(1)
            ->and($this->role->isVisibleTo($this->contributor))->toBeTrue();

        Notification::assertSentToTimes($this->contributor, OpenRoleInvited::class, 1);
        expect(Activity::query()->where('log_name', 'open_role')->where('event', 'invited')->count())->toBe(1);
    });

    it('stays quiet while the role is still a draft', function () {
        Notification::fake();
        $draft = OpenRole::factory()->inviteOnly()->create();

        app(InviteToOpenRole::class)($draft, $this->contributor, $this->dm);

        Notification::assertNothingSent();
        expect($draft->invitedUsers()->count())->toBe(1);
    });

    it('only invites active contributors', function () {
        foreach ([contributorIn(OnboardingState::PendingReview), contributorIn(OnboardingState::Suspended), clientUser()] as $other) {
            expect(fn () => app(InviteToOpenRole::class)($this->role, $other, $this->dm))->toThrow(ActionBlocked::class);
        }

        expect($this->role->invitedUsers()->count())->toBe(0);
    });

    it('only keeps an invite list on invite-only roles that are still draft or published', function () {
        $members = OpenRole::factory()->published()->create();
        $closed = OpenRole::factory()->status(OpenRoleStatus::Closed)->inviteOnly()->create();

        expect(fn () => app(InviteToOpenRole::class)($members, $this->contributor, $this->dm))->toThrow(ActionBlocked::class)
            ->and(fn () => app(InviteToOpenRole::class)($closed, $this->contributor, $this->dm))->toThrow(ActionBlocked::class);
    });

    it('removes an invite and the contributor stops seeing the role', function () {
        app(InviteToOpenRole::class)($this->role, $this->contributor, $this->dm);

        app(RemoveOpenRoleInvite::class)($this->role, $this->contributor, $this->cm);

        expect($this->role->invitedUsers()->count())->toBe(0)
            ->and($this->role->isVisibleTo($this->contributor))->toBeFalse()
            ->and(Activity::query()->where('event', 'invite_removed')->count())->toBe(1);
    });

    it('is for roles managers only', function () {
        foreach ([$this->finance, $this->contributor, clientUser()] as $denied) {
            expect(fn () => app(InviteToOpenRole::class)($this->role, $this->contributor, $denied))->toThrow(AuthorizationException::class)
                ->and(fn () => app(RemoveOpenRoleInvite::class)($this->role, $this->contributor, $denied))->toThrow(AuthorizationException::class);
        }
    });
});

describe('publish as open role', function () {
    it('creates a linked draft from a project without copying the client name', function () {
        $organization = Organization::factory()->verified()->create(['name' => 'Secret Client Ltd']);
        $project = Project::factory()->status(ProjectStatus::Staffing)->create([
            'organization_id' => $organization->id,
            'title' => 'Distributor survey',
            'summary' => 'Survey the distributor base.',
        ]);
        $skill = Skill::factory()->create();
        $project->skills()->attach($skill);

        $role = app(PublishProjectAsOpenRole::class)($project, $this->dm);

        expect($role->status)->toBe(OpenRoleStatus::Draft)
            ->and($role->project_id)->toBe($project->id)
            ->and($role->title)->toBe('Distributor survey')
            ->and($role->summary)->toBe('Survey the distributor base.')
            ->and($role->skills->pluck('id')->all())->toBe([$skill->id])
            ->and(json_encode($role->toArray()))->not->toContain('Secret Client');
    });

    it('is only possible from SOW accepted until delivery', function (ProjectStatus $status, bool $allowed) {
        $project = Project::factory()->status($status)->create();

        if ($allowed) {
            expect(app(PublishProjectAsOpenRole::class)($project, $this->dm))->toBeInstanceOf(OpenRole::class);
        } else {
            expect(fn () => app(PublishProjectAsOpenRole::class)($project, $this->dm))->toThrow(ActionBlocked::class);
        }
    })->with([
        [ProjectStatus::Draft, false],
        [ProjectStatus::SowSent, false],
        [ProjectStatus::SowAccepted, true],
        [ProjectStatus::Staffing, true],
        [ProjectStatus::Active, true],
        [ProjectStatus::InQa, false],
        [ProjectStatus::Closed, false],
        [ProjectStatus::Cancelled, false],
    ]);

    it('is denied to finance and contributors', function () {
        $project = Project::factory()->status(ProjectStatus::Staffing)->create();

        foreach ([$this->finance, $this->contributor] as $denied) {
            expect(fn () => app(PublishProjectAsOpenRole::class)($project, $denied))->toThrow(AuthorizationException::class);
        }
    });
});
