<?php

use App\Actions\Imports\StoreImportUpload;
use App\Enums\AdminRole;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Exceptions\ActionBlocked;
use App\Filament\Admin\Resources\Imports\ImportResource;
use App\Filament\Admin\Resources\Imports\Pages\ListImports;
use App\Filament\Admin\Resources\Imports\Pages\ViewImport;
use App\Jobs\SendQueuedInvite;
use App\Models\Import;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

const ACCESS_HEADERS = ['name', 'email'];

function accessCsv(string $email = 'asha@example.com'): string
{
    return importCsv(ACCESS_HEADERS, [['Asha Rao', $email]]);
}

/** Put raw text in the temporary upload place and hand it to the upload Action. */
function storeRaw(User $actor, string $content, string $name = 'people.csv'): Import
{
    Storage::fake('local');
    Storage::disk('local')->put('import-uploads/raw', $content);

    return app(StoreImportUpload::class)($actor, ImportType::Contributors, 'import-uploads/raw', $name);
}

describe('upload checks', function () {
    beforeEach(fn () => $this->admin = adminUser());

    it('accepts a good file, stores it under a random name and clears the temporary copy', function () {
        $import = storeRaw($this->admin, accessCsv(), '../../etc/people list.csv');

        expect($import->status)->toBe(ImportStatus::Uploaded)
            ->and($import->original_name)->toBe('people list.csv')
            ->and($import->file_path)->toMatch('~^imports/[0-9a-z]{26}\.csv$~')
            ->and($import->total_rows)->toBe(1)
            ->and($import->created_by)->toBe($this->admin->id)
            ->and(Storage::disk('local')->exists('import-uploads/raw'))->toBeFalse();
    });

    it('refuses anything that is not a CSV, and removes what was uploaded', function (string $name, string $content, string $message) {
        expect(fn () => storeRaw($this->admin, $content, $name))->toThrow(ActionBlocked::class, $message);

        expect(Storage::disk('local')->exists('import-uploads/raw'))->toBeFalse()
            ->and(Import::query()->count())->toBe(0);
    })->with([
        'xlsx name' => ['people.xlsx', "PK\x03\x04binary", 'Only CSV files'],
        'php name' => ['shell.php', accessCsv(), 'Only CSV files'],
        'double extension' => ['people.csv.exe', accessCsv(), 'Only CSV files'],
        'zip disguised as csv' => ['people.csv', "PK\x03\x04\x14\x00binary", 'not a CSV'],
        'binary with nul bytes' => ['people.csv', "name,email\n\0\0\0,x", 'not a CSV'],
        'empty file' => ['people.csv', '', 'name every column'],
        'header only' => ['people.csv', "name,email\n", 'no data rows'],
        'blank header' => ['people.csv', "name,,email\nA,B,C\n", 'name every column'],
        'duplicate headers' => ['people.csv', "name,Name\nA,B\n", 'same name'],
    ]);

    it('refuses a file over the size limit', function () {
        config(['groibe.imports.max_file_kb' => 1]);

        expect(fn () => storeRaw($this->admin, importCsv(ACCESS_HEADERS, array_fill(0, 200, ['Asha Rao', 'asha@example.com']))))
            ->toThrow(ActionBlocked::class, 'larger than');
    });

    it('refuses a file with too many columns', function () {
        $headers = array_map(fn (int $i): string => "col{$i}", range(1, 61));

        expect(fn () => storeRaw($this->admin, importCsv($headers, [array_fill(0, 61, 'x')])))->toThrow(ActionBlocked::class, 'more than 60 columns');
    });

    it('refuses people who may not import at all', function (callable $actor) {
        expect(fn () => storeRaw($actor(), accessCsv()))->toThrow(AuthorizationException::class)
            ->and(Storage::disk('local')->exists('import-uploads/raw'))->toBeFalse();
    })->with([
        'finance' => [fn () => adminUser(AdminRole::FinanceOps)],
        'contributor' => [fn () => contributorUser()],
        'client' => [fn () => clientUser()],
    ]);
});

describe('who sees which import', function () {
    it('limits an admin to their own imports, and a super admin sees all of them', function () {
        $mine = Import::factory()->create(['created_by' => ($me = adminUser(AdminRole::DeliveryManager))->id]);
        $theirs = Import::factory()->create(['created_by' => adminUser(AdminRole::DeliveryManager)->id]);
        $super = adminUser();

        expect($me->can('view', $mine))->toBeTrue()
            ->and($me->can('view', $theirs))->toBeFalse()
            ->and($me->can('update', $theirs))->toBeFalse()
            ->and($super->can('view', $theirs))->toBeTrue()
            ->and($me->can('delete', $mine))->toBeFalse()
            ->and($super->can('delete', $mine))->toBeFalse();
    });

    it('lets an admin open only imports of a kind they may import', function () {
        $manager = adminUser(AdminRole::CommunityManager);
        $clients = Import::factory()->clients()->create(['created_by' => $manager->id]);
        $contributors = Import::factory()->create(['created_by' => $manager->id]);

        expect($manager->can('view', $contributors))->toBeTrue()
            ->and($manager->can('view', $clients))->toBeFalse();
    });

    it('answers 404 for another admin\'s import page and 403 for finance', function () {
        $import = Import::factory()->create();

        $this->actingAs(adminUser(AdminRole::DeliveryManager))->get(ImportResource::getUrl('view', ['record' => $import]))->assertNotFound();
        asUser(adminUser(AdminRole::FinanceOps))->get(ImportResource::getUrl('index'))->assertForbidden();
        asUser(contributorUser())->get(ImportResource::getUrl('index'))->assertForbidden();

        test()->flushSession();
        auth()->forgetGuards();
        $this->get(ImportResource::getUrl('index'))->assertRedirect();
    });

    it('lets the owner and a super admin open the page', function () {
        $owner = adminUser(AdminRole::DeliveryManager);
        $import = Import::factory()->create(['created_by' => $owner->id]);

        $this->actingAs($owner)->get(ImportResource::getUrl('view', ['record' => $import]))->assertOk();
        asUser(adminUser())->get(ImportResource::getUrl('view', ['record' => $import]))->assertOk();
    });

    it('lists only the imports the admin may open', function () {
        $owner = adminUser(AdminRole::DeliveryManager);
        $mine = Import::factory()->create(['created_by' => $owner->id, 'original_name' => 'mine.csv']);
        $theirs = Import::factory()->create(['original_name' => 'theirs.csv']);

        Livewire::actingAs($owner)->test(ListImports::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    });

    it('does not run page actions for an import the admin cannot open', function () {
        $import = Import::factory()->create();

        Livewire::actingAs(adminUser(AdminRole::DeliveryManager))->test(ViewImport::class, ['record' => $import->getKey()])->assertNotFound();
    });
});

describe('the screen, from upload to invites', function () {
    it('takes a file, matches columns, checks it, imports it and queues the invites', function () {
        Queue::fake([SendQueuedInvite::class]);
        Storage::fake('local');
        $admin = adminUser();

        $upload = UploadedFile::fake()->createWithContent('people.csv', importCsv(['Full name', 'Email address'], [['Asha Rao', 'asha@example.com'], ['Ravi Nair', 'bad']]));
        Livewire::actingAs($admin)->test(ListImports::class)
            ->callAction('uploadContributors', ['file' => $upload])
            ->assertHasNoActionErrors();

        $import = Import::query()->firstOrFail();
        expect($import->status)->toBe(ImportStatus::Uploaded)->and($import->original_name)->toBe('people.csv');

        $page = Livewire::actingAs($admin)->test(ViewImport::class, ['record' => $import->getKey()])
            ->assertActionVisible('map')
            ->assertActionHidden('start')
            ->callAction('map', ['name' => 'Full name', 'email' => 'Email address'])
            ->assertHasNoActionErrors()
            ->callAction('dryRun')
            ->assertActionVisible('start')
            ->assertActionVisible('report')
            ->assertSee('Would create')
            ->assertSee('valid email');

        expect($import->refresh()->status)->toBe(ImportStatus::Validated)->and(User::query()->where('email', 'asha@example.com')->exists())->toBeFalse();

        $page->callAction('start');
        $import->refresh();
        expect($import->status)->toBe(ImportStatus::Completed)->and([$import->success_rows, $import->failed_rows])->toBe([1, 1]);

        $page->assertActionHidden('start')->assertActionVisible('invites')->callAction('invites');
        Queue::assertPushed(SendQueuedInvite::class, 1);

        $page->callAction('report')->assertFileDownloaded('import-'.$import->id.'-report.csv');

        expect(Activity::query()->where('event', 'report_downloaded')->count())->toBe(1);
    });

    it('shows a refusal in words when the file cannot be used', function () {
        Storage::fake('local');

        Livewire::actingAs(adminUser())->test(ListImports::class)
            ->callAction('uploadContributors', ['file' => UploadedFile::fake()->createWithContent('people.csv', "name,email\n")])
            ->assertNotified('That did not go through');

        expect(Import::query()->count())->toBe(0);
    });

    it('hides the client upload from admins who may not import clients', function () {
        Livewire::actingAs(adminUser(AdminRole::CommunityManager))->test(ListImports::class)
            ->assertActionHidden('uploadClients')
            ->assertActionVisible('uploadContributors');
    });

    it('does not let a hidden action be driven by hand', function () {
        $import = Import::factory()->create(['created_by' => ($owner = adminUser())->id]);

        Livewire::actingAs($owner)->test(ViewImport::class, ['record' => $import->getKey()])
            ->assertActionHidden('start')
            ->assertActionHidden('invites')
            ->assertActionHidden('report');
    });
});
