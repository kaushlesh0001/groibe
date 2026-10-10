<?php

use App\Enums\AdminRole;
use App\Enums\FeatureFlag as Flag;
use App\Models\Category;
use App\Models\FeatureFlag;
use App\Models\ProjectType;
use App\Models\Setting;
use App\Models\Skill;
use App\Models\User;
use App\Support\PermissionMatrix;
use Database\Seeders\FeatureFlagSeeder;
use Database\Seeders\SuperAdminSeeder;
use Database\Seeders\TaxonomySeeder;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

it('seeds every admin role with its permission set', function () {
    foreach (AdminRole::cases() as $role) {
        expect(Role::findByName($role->value)->permissions->pluck('name')->sort()->values()->all())
            ->toBe(collect(PermissionMatrix::forRole($role))->unique()->sort()->values()->all());
    }

    expect(Role::query()->pluck('name')->all())->toContain('contributor', 'client');
});

it('gives super admin every permission', function () {
    expect(PermissionMatrix::forRole(AdminRole::SuperAdmin))->toEqual(PermissionMatrix::allPermissions());
});

it('matches spec section 5 for each admin column', function (AdminRole $role, string $permission, bool $expected) {
    expect(in_array($permission, PermissionMatrix::forRole($role), true))->toBe($expected);
})->with([
    [AdminRole::DeliveryManager, 'projects.approve', true],
    [AdminRole::DeliveryManager, 'payouts.create', false],
    [AdminRole::DeliveryManager, 'cms.view', false],
    [AdminRole::CommunityManager, 'contributors.approve', true],
    [AdminRole::CommunityManager, 'cms.delete', true],
    [AdminRole::CommunityManager, 'projects.update', false],
    [AdminRole::FinanceOps, 'payouts.delete', true],
    [AdminRole::FinanceOps, 'projects.view', true],
    [AdminRole::FinanceOps, 'projects.update', false],
    [AdminRole::FinanceOps, 'settings.view', false],
    [AdminRole::DeliveryManager, 'users.impersonate', false],
    [AdminRole::DeliveryManager, 'audit_log.view_all', false],
    [AdminRole::DeliveryManager, 'audit_log.view', true],
]);

it('keeps settings and flags super admin only', function () {
    $super = adminUser();

    foreach ([Setting::class, FeatureFlag::class] as $model) {
        foreach ([AdminRole::DeliveryManager, AdminRole::CommunityManager, AdminRole::FinanceOps] as $role) {
            expect(adminUser($role)->can('viewAny', $model))->toBeFalse()
                ->and(adminUser($role)->can('create', $model))->toBeFalse();
        }

        expect($super->can('viewAny', $model))->toBeTrue()
            ->and($super->can('create', $model))->toBeTrue();
    }

    expect(clientUser()->can('viewAny', Setting::class))->toBeFalse();
});

it('applies SCR-001 to skills and categories', function () {
    $skill = Skill::factory()->create();

    foreach ([Skill::class, Category::class] as $model) {
        $cm = adminUser(AdminRole::CommunityManager);
        $dm = adminUser(AdminRole::DeliveryManager);

        expect($cm->can('viewAny', $model))->toBeTrue()
            ->and($cm->can('create', $model))->toBeTrue()
            ->and($cm->can('delete', $skill))->toBeTrue()
            ->and($dm->can('viewAny', $model))->toBeTrue()
            ->and($dm->can('create', $model))->toBeFalse()
            ->and($dm->can('update', $skill))->toBeFalse()
            ->and(adminUser(AdminRole::FinanceOps)->can('viewAny', $model))->toBeFalse()
            ->and(adminUser()->can('create', $model))->toBeTrue()
            ->and(contributorUser()->can('viewAny', $model))->toBeFalse()
            ->and(clientUser()->can('viewAny', $model))->toBeFalse();
    }
});

it('applies SCR-001 to project types: delivery manager reads, only super admin writes', function () {
    $type = ProjectType::factory()->create();
    $dm = adminUser(AdminRole::DeliveryManager);
    $cm = adminUser(AdminRole::CommunityManager);

    expect($dm->can('viewAny', ProjectType::class))->toBeTrue()
        ->and($dm->can('view', $type))->toBeTrue()
        ->and($dm->can('create', ProjectType::class))->toBeFalse()
        ->and($dm->can('update', $type))->toBeFalse()
        ->and($cm->can('viewAny', ProjectType::class))->toBeFalse()
        ->and(adminUser()->can('update', $type))->toBeTrue()
        ->and(contributorUser()->can('viewAny', ProjectType::class))->toBeFalse();
});

it('makes the audit log read-only and scoped to own actions for non-super admins', function () {
    $super = adminUser();
    $dm = adminUser(AdminRole::DeliveryManager);

    $own = activity()->causedBy($dm)->log('own');
    $other = activity()->causedBy($super)->log('other');

    expect($dm->can('view', $own))->toBeTrue()
        ->and($dm->can('view', $other))->toBeFalse()
        ->and($super->can('view', $other))->toBeTrue()
        ->and($super->can('update', $other))->toBeFalse()
        ->and($super->can('delete', $other))->toBeFalse()
        ->and($super->can('create', Activity::class))->toBeFalse()
        ->and(contributorUser()->can('viewAny', Activity::class))->toBeFalse();
});

it('seeds every spec flag with its documented default', function () {
    $this->seed(FeatureFlagSeeder::class);

    expect(FeatureFlag::query()->count())->toBe(count(Flag::cases()));

    foreach (Flag::cases() as $flag) {
        expect((bool) FeatureFlag::query()->where('key', $flag->value)->value('enabled'))->toBe($flag->default());
    }

    expect(Flag::PublicClientReviews->default())->toBeFalse()
        ->and(Flag::ClientCanViewShortlist->default())->toBeFalse()
        ->and(Flag::OpenRoles->default())->toBeTrue();
});

it('seeds taxonomy with 40+ skills and the seven project types', function () {
    $this->seed(TaxonomySeeder::class);

    expect(Skill::query()->count())->toBeGreaterThanOrEqual(40)
        ->and(ProjectType::query()->count())->toBe(7)
        ->and(Category::query()->where('type', 'skill')->count())->toBe(9);
});

it('creates the super admin from env config and is idempotent', function () {
    config(['groibe.admin.email' => 'boss@example.com', 'groibe.admin.password' => 'a-long-password']);

    $this->seed(SuperAdminSeeder::class);
    $this->seed(SuperAdminSeeder::class);

    $user = User::query()->where('email', 'boss@example.com')->sole();

    expect($user->isSuperAdmin())->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();
});

it('does not create a super admin without env credentials', function () {
    config(['groibe.admin.email' => null, 'groibe.admin.password' => null]);

    $this->seed(SuperAdminSeeder::class);

    expect(User::query()->count())->toBe(0);
});
