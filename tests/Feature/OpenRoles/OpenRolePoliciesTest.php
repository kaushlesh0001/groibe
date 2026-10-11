<?php

use App\Enums\AdminRole;
use App\Enums\ApplicationStatus;
use App\Enums\OnboardingState;
use App\Enums\OpenRoleStatus;
use App\Enums\UserStatus;
use App\Models\Application;
use App\Models\OpenRole;

beforeEach(function () {
    $this->actors = [
        'super' => adminUser(),
        'dm' => adminUser(AdminRole::DeliveryManager),
        'cm' => adminUser(AdminRole::CommunityManager),
        'finance' => adminUser(AdminRole::FinanceOps),
        'active' => contributorIn(OnboardingState::Active),
        'pending' => contributorIn(OnboardingState::PendingReview),
        'suspended' => contributorIn(OnboardingState::Suspended),
        'client' => clientUser(),
    ];
});

function openRoleAbilities(array $actors, string $ability, mixed $subject): array
{
    return collect($actors)->map(fn ($actor) => $actor->can($ability, $subject))->all();
}

describe('OpenRolePolicy', function () {
    it('lets admins with the permission manage and everyone else do nothing', function () {
        $role = OpenRole::factory()->published()->create();

        expect(openRoleAbilities($this->actors, 'create', OpenRole::class))->toBe([
            'super' => true, 'dm' => true, 'cm' => true, 'finance' => false, 'active' => false, 'pending' => false, 'suspended' => false, 'client' => false,
        ])->and(openRoleAbilities($this->actors, 'update', $role))->toBe([
            'super' => true, 'dm' => true, 'cm' => true, 'finance' => false, 'active' => false, 'pending' => false, 'suspended' => false, 'client' => false,
        ])->and(openRoleAbilities($this->actors, 'manageInvites', $role))->toBe([
            'super' => true, 'dm' => true, 'cm' => true, 'finance' => false, 'active' => false, 'pending' => false, 'suspended' => false, 'client' => false,
        ]);
    });

    it('lets only community managers and super admins delete, and only an unused role', function () {
        $unused = OpenRole::factory()->create();
        $used = Application::factory()->create()->openRole;

        expect(openRoleAbilities($this->actors, 'delete', $unused))->toBe([
            'super' => true, 'dm' => false, 'cm' => true, 'finance' => false, 'active' => false, 'pending' => false, 'suspended' => false, 'client' => false,
        ])->and(array_filter(openRoleAbilities($this->actors, 'delete', $used)))->toBeEmpty();
    });

    it('lets admins read any role and active contributors only roles they may see', function () {
        $draft = OpenRole::factory()->create();
        $members = OpenRole::factory()->published()->create();
        $invite = OpenRole::factory()->published()->inviteOnly()->create();

        $viewDraft = openRoleAbilities($this->actors, 'view', $draft);
        $viewMembers = openRoleAbilities($this->actors, 'view', $members);
        $viewInvite = openRoleAbilities($this->actors, 'view', $invite);

        expect($viewDraft['active'])->toBeFalse()
            ->and($viewMembers['active'])->toBeTrue()
            ->and($viewInvite['active'])->toBeFalse()
            ->and(array_filter([$viewMembers['pending'], $viewMembers['suspended'], $viewMembers['client']]))->toBeEmpty()
            ->and(array_filter([$viewDraft['finance'], $viewMembers['finance']]))->toBeEmpty()
            ->and([$viewDraft['super'], $viewDraft['dm'], $viewDraft['cm']])->each->toBeTrue();

        $invite->invitedUsers()->attach($this->actors['active']->id);
        expect($this->actors['active']->can('view', $invite))->toBeTrue()
            ->and($this->actors['pending']->can('view', $invite))->toBeFalse();
    });

    it('lets an applicant keep reading a role after it closes', function () {
        $role = OpenRole::factory()->published()->create();
        Application::factory()->create(['open_role_id' => $role->id, 'user_id' => $this->actors['active']->id]);
        $role->forceFill(['status' => OpenRoleStatus::Closed])->save();

        expect($this->actors['active']->can('view', $role))->toBeTrue()
            ->and(contributorIn(OnboardingState::Active)->can('view', $role))->toBeFalse();
    });

    it('lets only active contributors apply, to open roles they can see', function () {
        $role = OpenRole::factory()->published()->create();
        $late = OpenRole::factory()->published()->create(['deadline' => now()->subDay()]);
        $draft = OpenRole::factory()->create();

        expect(openRoleAbilities($this->actors, 'apply', $role))->toBe([
            'super' => false, 'dm' => false, 'cm' => false, 'finance' => false, 'active' => true, 'pending' => false, 'suspended' => false, 'client' => false,
        ])->and($this->actors['active']->can('apply', $late))->toBeFalse()
            ->and($this->actors['active']->can('apply', $draft))->toBeFalse();
    });

    it('denies a suspended account everything through the shared before hook', function () {
        $role = OpenRole::factory()->published()->public()->create();
        $suspendedAdmin = adminUser();
        $suspendedAdmin->forceFill(['status' => UserStatus::Suspended])->save();

        expect($suspendedAdmin->can('update', $role))->toBeFalse()
            ->and($suspendedAdmin->can('create', OpenRole::class))->toBeFalse();
    });
});

describe('ApplicationPolicy', function () {
    beforeEach(function () {
        $this->application = Application::factory()->create(['user_id' => $this->actors['active']->id]);
    });

    it('lets the applicant and reviewing admins read it, and nobody else', function () {
        expect(openRoleAbilities($this->actors, 'view', $this->application))->toBe([
            'super' => true, 'dm' => true, 'cm' => true, 'finance' => false, 'active' => true, 'pending' => false, 'suspended' => false, 'client' => false,
        ]);
    });

    it('lets admins update (shortlist, reject) and approve (offer) as the matrix says', function () {
        $expected = ['super' => true, 'dm' => true, 'cm' => true, 'finance' => false, 'active' => false, 'pending' => false, 'suspended' => false, 'client' => false];

        expect(openRoleAbilities($this->actors, 'update', $this->application))->toBe($expected)
            ->and(openRoleAbilities($this->actors, 'approve', $this->application))->toBe($expected);
    });

    it('lets only active contributors create', function () {
        expect(openRoleAbilities($this->actors, 'create', Application::class))->toBe([
            'super' => false, 'dm' => false, 'cm' => false, 'finance' => false, 'active' => true, 'pending' => false, 'suspended' => false, 'client' => false,
        ]);
    });

    it('lets only the applicant withdraw, and only before an offer', function () {
        $allowed = [ApplicationStatus::Submitted, ApplicationStatus::Shortlisted];

        foreach (ApplicationStatus::cases() as $status) {
            $application = Application::factory()->status($status)->create(['user_id' => $this->actors['active']->id]);

            expect($this->actors['active']->can('withdraw', $application))->toBe(in_array($status, $allowed, true))
                ->and($this->actors['super']->can('withdraw', $application))->toBeFalse()
                ->and(contributorIn(OnboardingState::Active)->can('withdraw', $application))->toBeFalse();
        }
    });

    it('lets only the applicant respond, and only to an offer', function () {
        foreach (ApplicationStatus::cases() as $status) {
            $application = Application::factory()->status($status)->create(['user_id' => $this->actors['active']->id]);

            expect($this->actors['active']->can('respond', $application))->toBe($status === ApplicationStatus::Offered)
                ->and($this->actors['dm']->can('respond', $application))->toBeFalse()
                ->and(contributorIn(OnboardingState::Active)->can('respond', $application))->toBeFalse();
        }
    });

    it('stops answering an offer once the applicant is no longer active', function () {
        $offer = Application::factory()->status(ApplicationStatus::Offered)->create(['user_id' => $this->actors['suspended']->id]);

        expect($this->actors['suspended']->can('respond', $offer))->toBeFalse();
    });
});
