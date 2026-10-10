<?php

namespace Database\Seeders;

use App\Enums\AdminRole;
use App\Enums\UserRole;
use App\Support\PermissionMatrix;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionMatrix::allPermissions() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (AdminRole::cases() as $adminRole) {
            Role::findOrCreate($adminRole->value, 'web')
                ->syncPermissions(PermissionMatrix::forRole($adminRole));
        }

        // Contributor and client roles carry no admin permissions; their access is Policy based.
        foreach ([UserRole::Contributor, UserRole::Client] as $role) {
            Role::findOrCreate($role->value, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
