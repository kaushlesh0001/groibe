<?php

namespace App\Actions\Onboarding;

use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organizations\OrganizationSlug;
use Illuminate\Support\Facades\DB;

/**
 * Self-registration of a client (spec 6.11, 12): the account, a new organization with status `pending`, and the person as its
 * owner, together or not at all. It never links the person to an organization that already exists, whatever name or GSTIN is
 * given: joining an existing organization is done by its owner's invite or by an admin. An admin verifies the new organization
 * before it may submit a brief.
 */
class RegisterClient
{
    public function __construct(private RegisterAccount $registerAccount, private OrganizationSlug $slugs) {}

    /**
     * @param  array{name: string, email: string, password: string, designation?: ?string, organization: array<string, ?string>}  $data
     *                                                                                                                                   organization keys: name, legal_name, gstin, website, industry, size_band, city, state, country
     */
    public function __invoke(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = ($this->registerAccount)(UserRole::Client, $data['name'], $data['email'], $data['password']);
            $details = array_map(fn (?string $value): ?string => filled($value) ? trim($value) : null, $data['organization']);

            $organization = Organization::query()->create([
                'name' => $details['name'],
                'slug' => $this->slugs->unique((string) $details['name']),
                'legal_name' => $details['legal_name'] ?? null,
                'gstin' => isset($details['gstin']) ? strtoupper($details['gstin']) : null,
                'website' => $details['website'] ?? null,
                'industry' => $details['industry'] ?? null,
                'size_band' => $details['size_band'] ?? null,
                'city' => $details['city'] ?? null,
                'state' => $details['state'] ?? null,
                'country' => $details['country'] ?? 'India',
                'status' => OrganizationStatus::Pending,
                'created_by' => $user->getKey(),
            ]);

            $organization->users()->attach($user->getKey(), [
                'org_role' => OrgRole::Owner->value,
                'designation' => filled($data['designation'] ?? null) ? trim((string) $data['designation']) : null,
            ]);

            activity('organization')->causedBy($user)->performedOn($organization)->event('registered')->log('Organization registered, waiting for verification');

            return $user;
        });
    }
}
