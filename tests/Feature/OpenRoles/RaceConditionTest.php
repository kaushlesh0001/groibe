<?php

use App\Actions\Applications\SubmitApplication;
use App\Actions\OpenRoles\InviteToOpenRole;
use App\Enums\AdminRole;
use App\Enums\ApplicationStatus;
use App\Enums\OnboardingState;
use App\Exceptions\ActionBlocked;
use App\Models\Application;
use App\Models\OpenRole;
use Illuminate\Support\Facades\DB;

/*
 * SUGGESTIONS 151 and 163: two requests that race past the "already applied" and "already on the list" checks reach the unique
 * index. Each test plays the second request from inside the first one's transaction, which is the moment the race happens.
 */

it('answers a second application that wins the race with the normal message, not a server error', function () {
    $contributor = contributorIn(OnboardingState::Active);
    $role = OpenRole::factory()->published()->create();

    // The other tab's application lands between this request's check and its insert.
    Application::creating(function () use ($role, $contributor): void {
        static $done = false;

        if (! $done) {
            $done = true;
            DB::table('applications')->insert([
                'open_role_id' => $role->id, 'user_id' => $contributor->id, 'status' => ApplicationStatus::Submitted->value,
                'motivation' => 'First tab', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    expect(fn () => app(SubmitApplication::class)($role, $contributor, ['motivation' => 'Second tab']))
        ->toThrow(ActionBlocked::class, 'You have already applied to this role.');

    // The simulated first tab wrote its row inside the same transaction here, so the rollback takes it too. In real life it is
    // another connection's committed row. What matters is the answer: a plain message, not an uncaught database error.
});

it('still creates one application and one only in the normal case', function () {
    $contributor = contributorIn(OnboardingState::Active);
    $role = OpenRole::factory()->published()->create();

    $application = app(SubmitApplication::class)($role, $contributor, ['motivation' => 'Only tab']);

    expect($application->status)->toBe(ApplicationStatus::Submitted)
        ->and(fn () => app(SubmitApplication::class)($role, $contributor, ['motivation' => 'Again']))->toThrow(ActionBlocked::class, 'already applied');
});

it('reports that nothing was added when another admin put the same person on the list first', function () {
    $contributor = contributorIn(OnboardingState::Active);
    $role = OpenRole::factory()->published()->inviteOnly()->create();
    $admin = adminUser(AdminRole::DeliveryManager);

    $raced = false;
    DB::listen(function ($query) use (&$raced, $role, $contributor, $admin): void {
        // Right after this request's "is the person already listed" query, the other admin's insert lands.
        if (! $raced && str_contains($query->sql, 'select') && str_contains($query->sql, 'open_role_invites')) {
            $raced = true;
            DB::table('open_role_invites')->insert([
                'open_role_id' => $role->id, 'user_id' => $contributor->id, 'invited_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    // Nothing added, and no exception. (The simulated row sits in the same transaction, so the count is not checked here.)
    expect(app(InviteToOpenRole::class)($role, $contributor, $admin))->toBeFalse();
});
