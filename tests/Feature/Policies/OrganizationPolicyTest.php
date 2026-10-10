<?php

use App\Enums\AdminRole;
use App\Enums\OrgRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\User;

it('lets members view their own organization only', function () {
    $orgA = Organization::factory()->verified()->create();
    $orgB = Organization::factory()->verified()->create();
    $memberA = clientUser($orgA, OrgRole::Viewer);

    expect($memberA->can('view', $orgA))->toBeTrue()
        ->and($memberA->can('view', $orgB))->toBeFalse();
});

it('denies cross-organization update, even to an owner of another org', function () {
    $orgA = Organization::factory()->verified()->create();
    $orgB = Organization::factory()->verified()->create();
    $ownerA = clientUser($orgA, OrgRole::Owner);

    expect($ownerA->can('update', $orgA))->toBeTrue()
        ->and($ownerA->can('update', $orgB))->toBeFalse()
        ->and($ownerA->can('inviteMember', $orgB))->toBeFalse();
});

it('limits updates by org role: owner and manager edit, viewer reads', function () {
    $org = Organization::factory()->verified()->create();

    expect(clientUser($org, OrgRole::Owner)->can('update', $org))->toBeTrue()
        ->and(clientUser($org, OrgRole::Manager)->can('update', $org))->toBeTrue()
        ->and(clientUser($org, OrgRole::Viewer)->can('update', $org))->toBeFalse();
});

it('lets only the owner invite team members', function () {
    $org = Organization::factory()->verified()->create();

    expect(clientUser($org, OrgRole::Owner)->can('inviteMember', $org))->toBeTrue()
        ->and(clientUser($org, OrgRole::Manager)->can('inviteMember', $org))->toBeFalse()
        ->and(clientUser($org, OrgRole::Viewer)->can('inviteMember', $org))->toBeFalse();
});

it('denies contributors and guests-by-role any organization access', function () {
    $org = Organization::factory()->verified()->create();
    $contributor = contributorUser();

    expect($contributor->can('view', $org))->toBeFalse()
        ->and($contributor->can('update', $org))->toBeFalse()
        ->and($contributor->can('viewAny', Organization::class))->toBeFalse()
        ->and($contributor->can('create', Organization::class))->toBeFalse();
});

it('denies clients delete and create', function () {
    $org = Organization::factory()->verified()->create();
    $owner = clientUser($org, OrgRole::Owner);

    expect($owner->can('delete', $org))->toBeFalse()
        ->and($owner->can('create', Organization::class))->toBeFalse()
        ->and($owner->can('viewAny', Organization::class))->toBeFalse();
});

it('follows the admin matrix for organizations', function () {
    $org = Organization::factory()->create();

    $dm = adminUser(AdminRole::DeliveryManager);
    $cm = adminUser(AdminRole::CommunityManager);
    $fin = adminUser(AdminRole::FinanceOps);
    $super = adminUser();

    expect($dm->can('create', Organization::class))->toBeTrue()
        ->and($dm->can('update', $org))->toBeTrue()
        ->and($dm->can('delete', $org))->toBeFalse()
        ->and($cm->can('view', $org))->toBeTrue()
        ->and($cm->can('update', $org))->toBeFalse()
        ->and($fin->can('view', $org))->toBeTrue()
        ->and($fin->can('update', $org))->toBeFalse()
        ->and($super->can('delete', $org))->toBeTrue();
});

it('denies everything to a suspended member', function () {
    $org = Organization::factory()->verified()->create();
    $owner = clientUser($org, OrgRole::Owner);
    $owner->update(['status' => UserStatus::Suspended]);

    expect($owner->fresh()->can('view', $org))->toBeFalse();
});

it('does not trigger lazy loading when checking membership', function () {
    $org = Organization::factory()->verified()->create();
    $owner = clientUser($org);

    $owner = User::query()->findOrFail($owner->id);

    expect(fn () => $owner->can('update', Organization::query()->findOrFail($org->id)))->not->toThrow(Throwable::class);
});
