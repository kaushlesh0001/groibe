<?php

namespace App\Actions\Organizations;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * A client owner or manager edits their organization's profile (spec 5 "RU own org", SCR-002, spec 12). The name and GSTIN are
 * what an admin checks when verifying, so they can be changed only while the organization is pending; after that they are the admin's.
 */
class UpdateOrganizationProfile
{
    private const ALWAYS = ['legal_name', 'website', 'industry', 'size_band', 'description', 'city', 'state', 'country'];

    private const WHILE_PENDING = ['name', 'gstin'];

    /** @param  array<string, mixed>  $data */
    public function __invoke(Organization $organization, User $actor, array $data): Organization
    {
        Gate::forUser($actor)->authorize('update', $organization);

        $allowed = $organization->status === OrganizationStatus::Pending ? [...self::ALWAYS, ...self::WHILE_PENDING] : self::ALWAYS;

        $changes = [];

        foreach (array_intersect_key($data, array_flip($allowed)) as $field => $value) {
            $value = is_string($value) ? trim($value) : $value;
            $value = $value === '' ? null : $value;

            if ($field === 'gstin' && $value !== null) {
                $value = strtoupper((string) $value);
            }

            // The country is required; an empty one leaves the old value.
            if ($field === 'country' && $value === null) {
                continue;
            }

            if ($organization->{$field} !== $value) {
                $organization->{$field} = $value;
                $changes[] = $field;
            }
        }

        if ($changes !== []) {
            $organization->save();

            // Names the fields, never the values: the audit trail should not become a second copy of the profile.
            activity('organization')->causedBy($actor)->performedOn($organization)->event('profile_updated')
                ->withProperties(['fields' => $changes])->log('Organization profile updated by the client');
        }

        return $organization;
    }
}
