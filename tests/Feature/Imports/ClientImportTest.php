<?php

use App\Actions\Imports\RunImportDryRun;
use App\Actions\Imports\StartImport;
use App\Actions\Imports\StoreImportUpload;
use App\Enums\AdminRole;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Exceptions\ActionBlocked;
use App\Models\Import;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;

const CLIENT_HEADERS = ['organization_name', 'website', 'industry', 'size_band', 'city', 'state', 'gstin', 'contact_name', 'contact_email', 'contact_phone', 'designation', 'org_role', 'zoho_crm_id'];

/** A client row with the given overrides. @param array<string, string> $with @return list<string> */
function clientRow(array $with = []): array
{
    $row = array_merge([
        'organization_name' => 'Acme Foods', 'website' => 'https://www.acmefoods.in', 'industry' => 'Food', 'size_band' => '51-200',
        'city' => 'Kolkata', 'state' => 'West Bengal', 'gstin' => '', 'contact_name' => 'Meera Sen', 'contact_email' => 'meera@acmefoods.in',
        'contact_phone' => '9830000001', 'designation' => 'Founder', 'org_role' => '', 'zoho_crm_id' => '',
    ], $with);

    return array_map(fn (string $header): string => $row[$header], CLIENT_HEADERS);
}

beforeEach(function () {
    $this->admin = adminUser();
});

function orgRoleOf(string $email): ?OrgRole
{
    return User::query()->where('email', $email)->firstOrFail()->organizations()->firstOrFail()->pivot->org_role;
}

describe('grouping', function () {
    it('groups rows of the same organization into one, owner first and managers after', function () {
        $import = runImport(uploadImport($this->admin, ImportType::Clients, importCsv(CLIENT_HEADERS, [
            clientRow(),
            clientRow(['contact_name' => 'Dev Roy', 'contact_email' => 'dev@acmefoods.in', 'designation' => 'Head of Ops']),
            clientRow(['contact_name' => 'Isha Paul', 'contact_email' => 'isha@acmefoods.in', 'org_role' => 'viewer']),
            clientRow(['organization_name' => 'Beta Tools', 'website' => '', 'contact_name' => 'Ken Lal', 'contact_email' => 'ken@betatools.in']),
        ])), $this->admin);

        $acme = Organization::query()->where('name', 'Acme Foods')->firstOrFail();

        expect($import->status)->toBe(ImportStatus::Completed)
            ->and([$import->success_rows, $import->failed_rows, $import->skipped_rows])->toBe([4, 0, 0])
            ->and(Organization::query()->count())->toBe(2)
            ->and($acme->users()->count())->toBe(3)
            ->and(orgRoleOf('meera@acmefoods.in'))->toBe(OrgRole::Owner)
            ->and(orgRoleOf('dev@acmefoods.in'))->toBe(OrgRole::Manager)
            ->and(orgRoleOf('isha@acmefoods.in'))->toBe(OrgRole::Viewer)
            ->and(orgRoleOf('ken@betatools.in'))->toBe(OrgRole::Owner)
            ->and($acme->users()->where('email', 'dev@acmefoods.in')->first()->pivot->designation)->toBe('Head of Ops');
    });

    it('creates the organization pending with the file details and no contact data of its own', function () {
        runImport(uploadImport($this->admin, ImportType::Clients, importCsv(CLIENT_HEADERS, [clientRow(['gstin' => '19abcde1234f1z5', 'zoho_crm_id' => 'Z-1'])])), $this->admin);

        $organization = Organization::query()->firstOrFail();

        expect($organization->status)->toBe(OrganizationStatus::Pending)
            ->and($organization->can_submit_briefs)->toBeFalse()
            ->and($organization->is_public)->toBeFalse()
            ->and($organization->slug)->toBe('acme-foods')
            ->and($organization->gstin)->toBe('19ABCDE1234F1Z5')
            ->and($organization->zoho_crm_id)->toBe('Z-1')
            ->and($organization->website)->toBe('https://www.acmefoods.in')
            ->and($organization->created_by)->toBe($this->admin->id);
    });

    it('creates people pending with no password, a profile and the client role', function () {
        runImport(uploadImport($this->admin, ImportType::Clients, importCsv(CLIENT_HEADERS, [clientRow()])), $this->admin);

        $user = User::query()->where('email', 'meera@acmefoods.in')->firstOrFail();

        expect($user->role)->toBe(UserRole::Client)
            ->and($user->status)->toBe(UserStatus::Pending)
            ->and($user->password)->toBeNull()
            ->and($user->hasRole('client'))->toBeTrue()
            ->and($user->profile->phone)->toBe('9830000001');
    });

    it('gives each organization its own slug when names collide', function () {
        Organization::factory()->create(['name' => 'Acme Foods', 'slug' => 'acme-foods', 'website' => 'https://other.example']);

        runImport(uploadImport($this->admin, ImportType::Clients, importCsv(CLIENT_HEADERS, [clientRow()])), $this->admin);

        expect(Organization::query()->pluck('slug')->all())->toEqualCanonicalizing(['acme-foods', 'acme-foods-2']);
    });
});

describe('matching existing organizations', function () {
    it('adds new people to an organization matched by name and website, ignoring case, scheme and www', function () {
        $existing = Organization::factory()->create(['name' => 'ACME FOODS', 'website' => 'http://acmefoods.in/about']);

        runImport(uploadImport($this->admin, ImportType::Clients, importCsv(CLIENT_HEADERS, [clientRow()])), $this->admin);

        expect(Organization::query()->count())->toBe(1)
            ->and($existing->users()->where('email', 'meera@acmefoods.in')->exists())->toBeTrue();
    });

    it('matches by GSTIN even when the name differs, and the first person is owner only if there is none', function () {
        $existing = Organization::factory()->create(['name' => 'Acme Foods Pvt Ltd', 'gstin' => '19ABCDE1234F1Z5']);
        $owner = User::factory()->client()->create();
        $existing->users()->attach($owner->id, ['org_role' => OrgRole::Owner->value]);

        runImport(uploadImport($this->admin, ImportType::Clients, importCsv(CLIENT_HEADERS, [clientRow(['gstin' => '19ABCDE1234F1Z5'])])), $this->admin);

        expect(Organization::query()->count())->toBe(1)
            ->and(orgRoleOf('meera@acmefoods.in'))->toBe(OrgRole::Manager);
    });

    it('does not mix up two organizations with the same name and different websites', function () {
        Organization::factory()->create(['name' => 'Acme Foods', 'website' => 'https://acmefoods.in']);

        runImport(uploadImport($this->admin, ImportType::Clients, importCsv(CLIENT_HEADERS, [clientRow(['website' => 'https://acme-foods.com'])])), $this->admin);

        expect(Organization::query()->count())->toBe(2);
    });

    it('refuses a row whose GSTIN and name belong to different organizations', function () {
        Organization::factory()->create(['name' => 'Acme Foods', 'website' => 'https://www.acmefoods.in']);
        Organization::factory()->create(['name' => 'Other Co', 'gstin' => '19ABCDE1234F1Z5']);
        $import = uploadImport($this->admin, ImportType::Clients, importCsv(CLIENT_HEADERS, [clientRow(['gstin' => '19ABCDE1234F1Z5'])]));

        app(RunImportDryRun::class)($import, $this->admin);

        expect($import->refresh()->failed_rows)->toBe(1)
            ->and(importReport($import)[0]['message'])->toContain('GSTIN matches one organization');
    });

    it('refuses a row for an archived organization', function () {
        Organization::factory()->create(['name' => 'Acme Foods', 'website' => 'https://www.acmefoods.in'])->delete();
        $import = uploadImport($this->admin, ImportType::Clients, importCsv(CLIENT_HEADERS, [clientRow()]));

        app(RunImportDryRun::class)($import, $this->admin);

        expect(importReport($import->refresh())[0]['message'])->toContain('archived');
    });
});

describe('idempotency and refusals', function () {
    it('skips people who are already in the organization and creates nothing twice', function () {
        $csv = importCsv(CLIENT_HEADERS, [clientRow(), clientRow(['contact_name' => 'Dev Roy', 'contact_email' => 'dev@acmefoods.in'])]);
        runImport(uploadImport($this->admin, ImportType::Clients, $csv), $this->admin);

        $again = uploadImport($this->admin, ImportType::Clients, $csv);
        app(RunImportDryRun::class)($again, $this->admin);

        expect([$again->refresh()->success_rows, $again->skipped_rows])->toBe([0, 2])
            ->and(fn () => app(StartImport::class)($again, $this->admin))->toThrow(ActionBlocked::class)
            ->and(Organization::query()->count())->toBe(1)
            ->and(User::query()->where('role', UserRole::Client->value)->count())->toBe(2);
    });

    it('adds only the new person when a second file repeats one contact', function () {
        runImport(uploadImport($this->admin, ImportType::Clients, importCsv(CLIENT_HEADERS, [clientRow()])), $this->admin);

        $import = runImport(uploadImport($this->admin, ImportType::Clients, importCsv(CLIENT_HEADERS, [
            clientRow(),
            clientRow(['contact_name' => 'Dev Roy', 'contact_email' => 'dev@acmefoods.in']),
        ])), $this->admin);

        expect([$import->success_rows, $import->skipped_rows])->toBe([1, 1])
            ->and(Organization::query()->count())->toBe(1)
            ->and(orgRoleOf('dev@acmefoods.in'))->toBe(OrgRole::Manager);
    });

    it('refuses an email that already belongs to a client of another organization', function () {
        $other = Organization::factory()->create();
        $person = User::factory()->client()->create(['email' => 'meera@acmefoods.in']);
        $other->users()->attach($person->id, ['org_role' => OrgRole::Owner->value]);

        $import = uploadImport($this->admin, ImportType::Clients, importCsv(CLIENT_HEADERS, [clientRow(), clientRow(['contact_email' => 'dev@acmefoods.in', 'contact_name' => 'Dev'])]));
        $import = runImport($import, $this->admin);

        expect([$import->success_rows, $import->failed_rows])->toBe([1, 1])
            ->and(importReport($import)[0]['message'])->toContain('another organization')
            ->and($person->refresh()->organizations()->count())->toBe(1);
    });

    it('refuses an email that belongs to a contributor', function () {
        User::factory()->contributor()->create(['email' => 'meera@acmefoods.in']);
        $import = uploadImport($this->admin, ImportType::Clients, importCsv(CLIENT_HEADERS, [clientRow()]));

        app(RunImportDryRun::class)($import, $this->admin);

        expect(importReport($import->refresh())[0]['message'])->toContain('contributor account');
    });

    it('refuses bad GSTINs, websites and roles, and missing contact details', function () {
        $import = uploadImport($this->admin, ImportType::Clients, importCsv(CLIENT_HEADERS, [
            clientRow(['contact_email' => 'a@x.in', 'gstin' => '123']),
            clientRow(['contact_email' => 'b@x.in', 'website' => 'not a site']),
            clientRow(['contact_email' => 'c@x.in', 'org_role' => 'boss']),
            clientRow(['contact_email' => '']),
            clientRow(['contact_email' => 'd@x.in', 'contact_name' => '']),
            clientRow(['contact_email' => 'e@x.in', 'organization_name' => '']),
            clientRow(['contact_email' => 'ok@x.in', 'organization_name' => 'Fine Co', 'website' => '']),
        ]));

        app(RunImportDryRun::class)($import, $this->admin);

        expect([$import->refresh()->success_rows, $import->failed_rows])->toBe([1, 6]);
    });

    it('reports a repeated email under a different organization within one file', function () {
        $import = uploadImport($this->admin, ImportType::Clients, importCsv(CLIENT_HEADERS, [
            clientRow(),
            clientRow(['organization_name' => 'Beta Tools', 'website' => 'https://betatools.in']),
        ]));

        app(RunImportDryRun::class)($import, $this->admin);

        expect([$import->refresh()->success_rows, $import->failed_rows])->toBe([1, 1])
            ->and(importReport($import)[0]['message'])->toContain('another organization');
    });

    it('dry run groups the same way and writes nothing', function () {
        $import = uploadImport($this->admin, ImportType::Clients, importCsv(CLIENT_HEADERS, [
            clientRow(),
            clientRow(['contact_name' => 'Dev Roy', 'contact_email' => 'dev@acmefoods.in']),
        ]));

        app(RunImportDryRun::class)($import, $this->admin);

        expect([$import->refresh()->total_rows, $import->success_rows])->toBe([2, 2])
            ->and(Organization::query()->count())->toBe(0)
            ->and(User::query()->where('role', UserRole::Client->value)->count())->toBe(0);
    });
});

describe('permissions', function () {
    it('lets super admins and delivery managers import clients, and nobody else', function (AdminRole $role, bool $allowed) {
        $actor = adminUser($role);

        expect($actor->can('create', [Import::class, ImportType::Clients]))->toBe($allowed);
    })->with([
        'super admin' => [AdminRole::SuperAdmin, true],
        'delivery manager' => [AdminRole::DeliveryManager, true],
        'community manager' => [AdminRole::CommunityManager, false],
        'finance' => [AdminRole::FinanceOps, false],
    ]);

    it('refuses an upload from an admin without the permission', function () {
        Storage::fake('local');
        Storage::disk('local')->put('import-uploads/x.csv', importCsv(CLIENT_HEADERS, [clientRow()]));

        expect(fn () => app(StoreImportUpload::class)(adminUser(AdminRole::CommunityManager), ImportType::Clients, 'import-uploads/x.csv', 'x.csv'))
            ->toThrow(AuthorizationException::class);

        expect(Storage::disk('local')->exists('import-uploads/x.csv'))->toBeFalse()
            ->and(Import::query()->count())->toBe(0);
    });
});
