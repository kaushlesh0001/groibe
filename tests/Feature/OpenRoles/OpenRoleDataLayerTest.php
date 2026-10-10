<?php

use App\Enums\ApplicationStatus;
use App\Enums\OnboardingState;
use App\Enums\OpenRoleStatus;
use App\Models\Application;
use App\Models\OpenRole;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

it('allows exactly the open role transitions of spec 6.4', function () {
    $edges = [
        'draft' => ['published'],
        'published' => ['closed', 'filled', 'archived'],
        'closed' => [],
        'filled' => [],
        'archived' => [],
    ];

    foreach (OpenRoleStatus::cases() as $from) {
        expect(array_map(fn ($s) => $s->value, $from->allowedTransitions()))->toEqualCanonicalizing($edges[$from->value]);
    }
});

it('allows exactly the application transitions of spec 6.5 plus the provisional reapply edge', function () {
    $edges = [
        'submitted' => ['shortlisted', 'rejected', 'withdrawn'],
        'shortlisted' => ['offered', 'rejected', 'withdrawn'],
        'offered' => ['accepted', 'rejected', 'declined'],
        'withdrawn' => ['submitted'],
        'accepted' => [],
        'rejected' => [],
        'declined' => [],
    ];

    foreach (ApplicationStatus::cases() as $from) {
        expect(array_map(fn ($s) => $s->value, $from->allowedTransitions()))->toEqualCanonicalizing($edges[$from->value]);
    }
});

it('shows guests and non-contributors public published roles only', function () {
    $public = OpenRole::factory()->published()->public()->create();
    OpenRole::factory()->published()->create();
    OpenRole::factory()->published()->inviteOnly()->create();
    OpenRole::factory()->public()->create();

    expect(OpenRole::query()->visibleTo(null)->pluck('id')->all())->toBe([$public->id])
        ->and(OpenRole::query()->visibleTo(clientUser())->pluck('id')->all())->toBe([$public->id])
        ->and(OpenRole::query()->visibleTo(adminUser())->pluck('id')->all())->toBe([$public->id]);
});

it('shows active contributors members-only roles and the invite-only roles they are invited to', function () {
    $public = OpenRole::factory()->published()->public()->create();
    $members = OpenRole::factory()->published()->create();
    $invitedTo = OpenRole::factory()->published()->inviteOnly()->create();
    OpenRole::factory()->published()->inviteOnly()->create();
    OpenRole::factory()->create();

    $active = contributorIn(OnboardingState::Active);
    $invitedTo->invitedUsers()->attach($active->id);
    $other = contributorIn(OnboardingState::Active);

    expect(OpenRole::query()->visibleTo($active)->pluck('id')->all())->toEqualCanonicalizing([$public->id, $members->id, $invitedTo->id])
        ->and(OpenRole::query()->visibleTo($other)->pluck('id')->all())->toEqualCanonicalizing([$public->id, $members->id]);
});

it('hides members-only and invite-only roles from contributors who are not active', function () {
    $public = OpenRole::factory()->published()->public()->create();
    $members = OpenRole::factory()->published()->create();
    $invite = OpenRole::factory()->published()->inviteOnly()->create();

    $pending = contributorIn(OnboardingState::PendingReview);
    $suspended = contributorIn(OnboardingState::Suspended);
    $invite->invitedUsers()->attach([$pending->id, $suspended->id]);

    foreach ([$pending, $suspended] as $user) {
        expect(OpenRole::query()->visibleTo($user)->pluck('id')->all())->toBe([$public->id])
            ->and($members->isVisibleTo($user))->toBeFalse();
    }
});

it('stops taking applications once the deadline has passed or the role is not published', function () {
    expect(OpenRole::factory()->published()->make(['deadline' => now()->addDay()])->isOpenForApplications())->toBeTrue()
        ->and(OpenRole::factory()->published()->make(['deadline' => now()->toDateString()])->isOpenForApplications())->toBeTrue()
        ->and(OpenRole::factory()->published()->make(['deadline' => now()->subDay()])->isOpenForApplications())->toBeFalse()
        ->and(OpenRole::factory()->make()->isOpenForApplications())->toBeFalse();
});

it('freezes the slug once a role has been published', function () {
    $draft = OpenRole::factory()->create(['slug' => 'first-slug']);
    $draft->update(['slug' => 'second-slug']);

    $published = OpenRole::factory()->published()->create(['slug' => 'live-slug']);

    expect(fn () => $published->update(['slug' => 'changed']))->toThrow(LogicException::class)
        ->and($published->fresh()->slug)->toBe('live-slug');
});

it('keeps one application per contributor and role', function () {
    $application = Application::factory()->create();

    expect(fn () => Application::factory()->create(['open_role_id' => $application->open_role_id, 'user_id' => $application->user_id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('refuses to delete a role or contributor that has applications', function () {
    $application = Application::factory()->create();

    expect(fn () => $application->openRole->delete())->toThrow(QueryException::class);
});
