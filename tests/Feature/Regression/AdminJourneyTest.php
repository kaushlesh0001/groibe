<?php

use App\Actions\Admin\StartImpersonation;
use App\Actions\Admin\StopImpersonation;
use App\Actions\Imports\RunImportDryRun;
use App\Actions\Imports\StartImport;
use App\Enums\AdminRole;
use App\Enums\BriefStatus;
use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Enums\OrgRole;
use App\Enums\ProjectStatus;
use App\Enums\UserStatus;
use App\Exceptions\ActionBlocked;
use App\Filament\Admin\Resources\Activities\Pages\ListActivities;
use App\Filament\Admin\Resources\Contributors\ContributorResource;
use App\Filament\Admin\Resources\Contributors\Pages\ListContributors;
use App\Filament\Admin\Resources\EmailLogs\EmailLogResource;
use App\Filament\Admin\Resources\Imports\Pages\ViewImport;
use App\Filament\Admin\Resources\Payouts\PayoutResource;
use App\Filament\Admin\Resources\Settings\SettingResource;
use App\Models\Brief;
use App\Models\Import;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Skill;
use App\Models\User;
use App\Notifications\InviteNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/helpers.php';

/*
 * Pass 9 item 7, persona 4 of 4: the admin. Spec 20: "Import of contributors and clients works with a dry run and an error report",
 * "Admin can ... record payouts" and "All policy tests pass". The journey follows a delivery manager and a super admin through the
 * panel: what the dashboard shows each of them, importing people safely, inviting them, exporting, impersonating, and the audit trail.
 */

const ADMIN_JOURNEY_PEOPLE = ['name', 'email', 'phone', 'city', 'state', 'headline', 'bio', 'skills', 'experience_years', 'linkedin_url', 'availability', 'status'];

const ADMIN_JOURNEY_CLIENTS = ['organization_name', 'website', 'industry', 'size_band', 'city', 'state', 'gstin', 'contact_name', 'contact_email', 'contact_phone', 'designation', 'org_role', 'zoho_crm_id'];

it('takes an admin from the dashboard through a safe import, invites, export, impersonation and the audit trail', function () {
    Storage::fake('local');
    Notification::fake();

    $super = adminUser();
    $dm = adminUser(AdminRole::DeliveryManager);

    // 1. The dashboard opens for every admin, fills from the data, and shows operator information to the super admin only.
    Brief::factory()->status(BriefStatus::Submitted)->create(['title' => 'Dashboard brief']);
    Project::factory()->status(ProjectStatus::Active)->create(['delivery_manager_id' => $dm->id]);

    $this->actingAs($super)->get('/admin')->assertOk()->assertSee('System status')->assertSee('Database size');
    asUser($dm)->get('/admin')->assertOk()->assertDontSee('System status');

    // 2. Import contributors. The check saves nothing and reports the bad row; the real run creates the good one.
    $csv = importCsv(ADMIN_JOURNEY_PEOPLE, [
        ['Asha Rao', 'asha@import.test', '9800000001', 'Pune', 'MH', 'Analyst', 'Bio text', 'Survey design;Brand new skill', '5', '', 'available', ''],
        ['Bad Row', 'not-an-email', '', '', '', '', '', '', '', '', '', ''],
    ]);
    $import = uploadImport($dm, ImportType::Contributors, $csv);

    app(RunImportDryRun::class)($import, $dm);
    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Validated)
        ->and([$import->success_rows, $import->failed_rows])->toBe([1, 1])
        ->and(User::query()->where('email', 'asha@import.test')->exists())->toBeFalse()
        ->and(collect(importReport($import))->contains(fn (array $row): bool => $row['severity'] === 'error' && $row['name'] === 'Bad Row'))->toBeTrue();

    app(StartImport::class)($import->refresh(), $dm);
    $import->refresh();

    $asha = User::query()->where('email', 'asha@import.test')->firstOrFail();
    expect($import->status)->toBe(ImportStatus::Completed)
        ->and($asha->status)->toBe(UserStatus::Pending)->and($asha->password)->toBeNull()
        ->and(User::query()->where('email', 'not-an-email')->exists())->toBeFalse()
        ->and(Skill::query()->where('name', 'Brand new skill')->value('is_active'))->toBeFalse();

    // The error report downloads from the import page (before another upload, which starts a fresh fake disk in this test).
    Livewire::actingAs($dm)->test(ViewImport::class, ['record' => $import->getKey()])->callAction('report')->assertFileDownloaded();

    // 3. The same file again creates nobody twice: the check finds nothing to create, and starting it is refused.
    $repeat = app(RunImportDryRun::class)(uploadImport($dm, ImportType::Contributors, $csv), $dm)->refresh();
    expect([$repeat->success_rows, $repeat->skipped_rows])->toBe([0, 1])
        ->and(fn () => app(StartImport::class)($repeat, $dm))->toThrow(ActionBlocked::class, 'Nothing in this file')
        ->and(User::query()->where('email', 'asha@import.test')->count())->toBe(1);

    // 4. Clients: two contacts of one organization are grouped under it, the first as owner.
    $clients = runImport(uploadImport($dm, ImportType::Clients, importCsv(ADMIN_JOURNEY_CLIENTS, [
        ['Acme Foods', 'https://acme.example', 'FMCG', '50-200', 'Mumbai', 'MH', '', 'Ravi Shah', 'ravi@acme.example', '', 'Founder', '', ''],
        ['Acme Foods', 'https://acme.example', 'FMCG', '50-200', 'Mumbai', 'MH', '', 'Neha Jain', 'neha@acme.example', '', 'Ops', '', ''],
    ])), $dm);
    $acme = Organization::query()->where('name', 'Acme Foods')->sole();
    expect($clients->status)->toBe(ImportStatus::Completed)
        ->and(Organization::query()->where('name', 'Acme Foods')->count())->toBe(1)
        ->and($acme->users()->count())->toBe(2)
        ->and(User::query()->where('email', 'ravi@acme.example')->first()->organizationRole($acme))->toBe(OrgRole::Owner)
        ->and(User::query()->where('email', 'neha@acme.example')->first()->organizationRole($acme))->toBe(OrgRole::Manager);

    // 5. The invite button sends a signed activation link.
    Livewire::actingAs($dm)->test(ViewImport::class, ['record' => $import->getKey()])->callAction('invites');

    $inviteUrl = null;
    Notification::assertSentTo($asha, InviteNotification::class, function (InviteNotification $notice) use (&$inviteUrl): bool {
        $inviteUrl = $notice->url;

        return true;
    });
    // The invited person is a guest on their own device, not the admin's session.
    auth()->forgetGuards();
    $this->flushSession();
    $this->get($inviteUrl)->assertOk()->assertSee('Activate your account');

    // 6. The person activates: password set, email confirmed, signed in.
    Volt::test('auth.invite', ['token' => $asha->uuid])->set('password', 'a-long-password')->set('password_confirmation', 'a-long-password')
        ->call('activate')->assertHasNoErrors();
    expect($asha->refresh()->password)->not->toBeNull()->and($asha->hasVerifiedEmail())->toBeTrue();

    // 7. Export. The delivery manager downloads the contributor list; the file is written to the audit log with its row count.
    Livewire::actingAs($dm)->test(ListContributors::class)->set('activeTab', 'all')->callAction('export')->assertFileDownloaded();
    expect(Activity::query()->where('causer_id', $dm->id)->where('log_name', 'like', '%export%')->exists())->toBeTrue();

    // 8. The super admin looks at a client's screen as that client, with the banner, and the start and stop are logged.
    $ravi = User::query()->where('email', 'ravi@acme.example')->firstOrFail();
    $ravi->forceFill(['password' => 'a-long-password', 'email_verified_at' => now(), 'status' => UserStatus::Active])->save();

    $this->actingAs($super);
    app(StartImpersonation::class)($super, $ravi);
    $this->get('/app/client')->assertOk()->assertSee('Impersonating '.$ravi->name, false);
    $this->post(route('impersonate.stop'))->assertRedirect('/admin');
    expect(Activity::query()->where('log_name', 'impersonation')->pluck('event')->all())->toBe(['started', 'stopped']);

    // 9. The audit trail: the super admin reads everything, the delivery manager only their own actions.
    $own = Activity::query()->where('causer_id', $dm->id)->latest('id')->first();
    $other = Activity::query()->where('log_name', 'impersonation')->first();
    $byLog = fn (User $admin, string $log) => Livewire::actingAs($admin)->test(ListActivities::class)->filterTable('log_name', $log);

    $byLog($super, 'impersonation')->assertCanSeeTableRecords([$other]);
    $byLog($super, $own->log_name)->assertCanSeeTableRecords([$own]);
    $byLog($dm, $own->log_name)->assertCanSeeTableRecords([$own]);
    $byLog($dm, 'impersonation')->assertCanNotSeeTableRecords([$other]);
});

it('keeps each admin role to the screens its permissions allow', function () {
    $matrix = [
        // [role, url, may open]
        [AdminRole::FinanceOps, ContributorResource::getUrl('index'), true],   // read-only
        [AdminRole::FinanceOps, PayoutResource::getUrl('index'), true],
        [AdminRole::CommunityManager, PayoutResource::getUrl('index'), false],
        [AdminRole::DeliveryManager, EmailLogResource::getUrl('index'), false],
        [AdminRole::FinanceOps, EmailLogResource::getUrl('index'), false],
        [AdminRole::DeliveryManager, SettingResource::getUrl('index'), false],
        [AdminRole::CommunityManager, SettingResource::getUrl('index'), false],
        [AdminRole::SuperAdmin, EmailLogResource::getUrl('index'), true],
        [AdminRole::SuperAdmin, SettingResource::getUrl('index'), true],
    ];

    foreach ($matrix as [$role, $url, $allowed]) {
        $status = asUser(adminUser($role))->get($url)->getStatusCode();

        expect($allowed ? $status === 200 : $status === 403)->toBeTrue("{$role->value} on {$url} got {$status}");
    }
});

it('never lets an admin account be reached from the client or contributor apps, or their data from a non-super admin', function () {
    $dm = adminUser(AdminRole::DeliveryManager);
    $target = clientUser();

    expect(fn () => app(StartImpersonation::class)($dm, $target))->toThrow(AuthorizationException::class);
    expect(Import::query()->count())->toBe(0);

    app(StopImpersonation::class);
    asUser($dm)->get('/app/client')->assertForbidden();
    asUser($dm)->get('/app/contributor')->assertForbidden();
});
