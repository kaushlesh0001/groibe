<?php

use App\Actions\Imports\RunImportDryRun;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Models\Organization;
use App\Models\User;

it('imports the sample contributors file the admins are given', function () {
    $admin = adminUser();
    $import = uploadImport($admin, ImportType::Contributors, (string) file_get_contents(base_path('docs/import-samples/contributors.csv')));

    app(RunImportDryRun::class)($import, $admin);
    expect([$import->refresh()->total_rows, $import->success_rows, $import->failed_rows])->toBe([2, 2, 0]);

    $import = runImport($import, $admin);

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and(User::query()->where('email', 'asha.rao@example.com')->firstOrFail()->skills()->count())->toBe(2);
});

it('imports the sample clients file the admins are given', function () {
    $admin = adminUser();
    $import = runImport(uploadImport($admin, ImportType::Clients, (string) file_get_contents(base_path('docs/import-samples/clients.csv'))), $admin);

    expect($import->status)->toBe(ImportStatus::Completed)
        ->and([$import->success_rows, $import->failed_rows])->toBe([3, 0])
        ->and(Organization::query()->count())->toBe(2)
        ->and(Organization::query()->where('name', 'Acme Foods')->firstOrFail()->users()->count())->toBe(2);
});
