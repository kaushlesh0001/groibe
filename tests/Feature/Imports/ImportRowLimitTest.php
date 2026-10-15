<?php

use App\Enums\ImportType;
use App\Exceptions\ActionBlocked;
use App\Models\Import;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** A contributor file of the given number of data rows. */
function peopleCsv(int $rows): string
{
    return importCsv(
        ['name', 'email'],
        array_map(fn (int $n): array => ["Person {$n}", "person{$n}@example.test"], range(1, $rows)),
    );
}

it('takes a file of exactly the row limit and refuses one more, with a message that says how to proceed', function () {
    config(['groibe.imports.max_rows' => 50]);
    $admin = adminUser();

    $import = uploadImport($admin, ImportType::Contributors, peopleCsv(50));
    expect($import->total_rows)->toBe(50);

    expect(fn () => uploadImport($admin, ImportType::Contributors, peopleCsv(51)))
        ->toThrow(ActionBlocked::class, 'more than 50 rows. Split it into files of at most 50 rows');
});

it('leaves nothing behind when a file is too long', function () {
    config(['groibe.imports.max_rows' => 10]);

    try {
        uploadImport(adminUser(), ImportType::Contributors, peopleCsv(11));
    } catch (ActionBlocked) {
    }

    expect(Import::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('defaults the limit to 5,000 rows, the same as the export limit', function () {
    expect(config('groibe.imports.max_rows'))->toBe(5000);
});

it('indexes organizations by GSTIN, for the import lookup and the registration check', function () {
    expect(Schema::hasIndex('organizations', ['gstin']))->toBeTrue();
});
