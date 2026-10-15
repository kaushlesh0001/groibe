<?php

use App\Enums\AdminRole;
use App\Enums\OrganizationStatus;
use App\Filament\Admin\Resources\Organizations\Pages\EditOrganization;
use App\Models\Organization;
use App\Models\User;
use App\Services\Exports\Definitions\OrganizationExport;
use App\Services\Exports\Exporter;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/** Saves a status on the admin edit screen, as an admin would. */
function setOrganizationStatus(Organization $organization, string $status, ?User $admin = null): void
{
    Livewire::actingAs($admin ?? adminUser())->test(EditOrganization::class, ['record' => $organization->getRouteKey()])
        ->fillForm(['status' => $status])->call('save')->assertHasNoFormErrors();
}

/** The "Verified on" cell of an organization in the CSV export, or null when it is empty. */
function exportedVerifiedOn(Organization $organization): ?string
{
    ob_start();
    app(Exporter::class)->export(new OrganizationExport, Organization::query()->whereKey($organization->getKey()), adminUser(AdminRole::DeliveryManager))->sendContent();
    $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", str_replace("\r", '', ltrim((string) ob_get_clean(), "\xEF\xBB\xBF"))))));

    $cell = $rows[1][array_search('Verified on', $rows[0], true)] ?? null;

    return $cell === '' ? null : $cell;
}

it('stamps verified_at when an admin verifies an organization', function () {
    $organization = Organization::factory()->create(['status' => OrganizationStatus::Pending]);
    expect($organization->verified_at)->toBeNull();

    $this->travelTo(now()->setDate(2026, 3, 4)->setTime(10, 30));
    setOrganizationStatus($organization, 'verified');

    expect($organization->refresh()->status)->toBe(OrganizationStatus::Verified)
        ->and($organization->verified_at->toDateTimeString())->toBe('2026-03-04 10:30:00');
});

it('records who verified it in the audit log, with the date', function () {
    $organization = Organization::factory()->create(['status' => OrganizationStatus::Pending]);
    $admin = adminUser(AdminRole::DeliveryManager);

    setOrganizationStatus($organization, 'verified', $admin);

    $entry = Activity::query()->where('subject_type', $organization->getMorphClass())->where('subject_id', $organization->id)->latest('id')->firstOrFail();

    expect($entry->causer_id)->toBe($admin->id)
        ->and($entry->properties['attributes']['status'])->toBe('verified')
        ->and($entry->properties['attributes']['verified_at'])->not->toBeNull()
        ->and($entry->properties['old']['status'])->toBe('pending');
});

it('clears verified_at when verification is withdrawn, by suspension or by sending it back to pending', function (string $status) {
    $organization = Organization::factory()->verified()->create();
    expect($organization->verified_at)->not->toBeNull();

    setOrganizationStatus($organization, $status);

    expect($organization->refresh()->status->value)->toBe($status)->and($organization->verified_at)->toBeNull();
})->with(['suspended', 'pending']);

it('stamps a fresh date when an organization is verified again', function () {
    $organization = Organization::factory()->create(['status' => OrganizationStatus::Pending]);

    $this->travelTo(now()->setDate(2026, 1, 10)->setTime(9, 0));
    setOrganizationStatus($organization, 'verified');
    setOrganizationStatus($organization, 'suspended');
    $this->travelTo(now()->setDate(2026, 2, 20)->setTime(9, 0));
    setOrganizationStatus($organization, 'verified');

    expect($organization->refresh()->verified_at->toDateString())->toBe('2026-02-20');
});

it('leaves the date alone when something else about a verified organization changes', function () {
    $organization = Organization::factory()->verified()->create(['verified_at' => '2026-01-05 08:00:00', 'name' => 'Old name']);

    $this->travelTo(now()->setDate(2026, 6, 1));
    Livewire::actingAs(adminUser())->test(EditOrganization::class, ['record' => $organization->getRouteKey()])
        ->fillForm(['name' => 'New name'])->call('save')->assertHasNoFormErrors();

    expect($organization->refresh()->name)->toBe('New name')->and($organization->verified_at->toDateTimeString())->toBe('2026-01-05 08:00:00');
});

it('stamps the date on an organization created as verified, and not on one created pending', function () {
    expect(Organization::factory()->create(['status' => OrganizationStatus::Verified, 'verified_at' => null])->verified_at)->not->toBeNull()
        ->and(Organization::factory()->create(['status' => OrganizationStatus::Pending])->verified_at)->toBeNull()
        ->and(Organization::factory()->create(['status' => OrganizationStatus::Suspended])->verified_at)->toBeNull();
});

it('shows the verified date in the export, and nothing once verification is withdrawn', function () {
    $organization = Organization::factory()->create(['status' => OrganizationStatus::Pending]);
    expect(exportedVerifiedOn($organization))->toBeNull();

    $this->travelTo(now()->setDate(2026, 3, 4)->setTime(10, 30));
    setOrganizationStatus($organization, 'verified');
    expect(exportedVerifiedOn($organization))->toBe('2026-03-04');

    setOrganizationStatus($organization, 'suspended');
    expect(exportedVerifiedOn($organization))->toBeNull();
});
