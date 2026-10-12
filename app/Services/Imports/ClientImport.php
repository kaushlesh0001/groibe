<?php

namespace App\Services\Imports;

use App\Enums\ImportType;
use App\Enums\OnboardingState;
use App\Enums\OrganizationStatus;
use App\Enums\OrgRole;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Organization;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The clients CSV (spec 15.1). Rows with the same organization are one Organization with several people: it is matched
 * by GSTIN, or by name plus website, and created `pending` when it is new. The first person of an organization is its owner
 * and later ones are managers, unless the row names a role. A person whose email already has an account is skipped, so a
 * second run of the same file changes nothing. A client belongs to one organization (spec 4), so an email that already
 * belongs to another organization refuses the row.
 */
class ClientImport extends ImportDefinition
{
    /** @var array<string, array{id: int|null, has_owner: bool}> organization key => what this run knows about it */
    private array $organizations = [];

    /** @var array<string, string> emails seen earlier in this run => organization key */
    private array $seenEmails = [];

    public function type(): ImportType
    {
        return ImportType::Clients;
    }

    public function fields(): array
    {
        return [
            'organization_name' => ['label' => 'Organization name', 'required' => true],
            'website' => ['label' => 'Website', 'required' => false],
            'industry' => ['label' => 'Industry', 'required' => false],
            'size_band' => ['label' => 'Size band', 'required' => false],
            'city' => ['label' => 'City', 'required' => false],
            'state' => ['label' => 'State', 'required' => false],
            'gstin' => ['label' => 'GSTIN', 'required' => false],
            'contact_name' => ['label' => 'Contact name', 'required' => true],
            'contact_email' => ['label' => 'Contact email', 'required' => true],
            'contact_phone' => ['label' => 'Contact phone', 'required' => false],
            'designation' => ['label' => 'Designation', 'required' => false],
            'org_role' => ['label' => 'Role in the organization', 'required' => false],
            'zoho_crm_id' => ['label' => 'Zoho CRM id', 'required' => false],
        ];
    }

    public function process(array $row, int $line): RowResult
    {
        $row['contact_email'] = Str::lower($row['contact_email'] ?? '');
        $row['gstin'] = Str::upper($row['gstin'] ?? '');
        $row['org_role'] = Str::lower($row['org_role'] ?? '');

        $validator = Validator::make($row, [
            'organization_name' => ['required', 'string', 'max:255'],
            'website' => ['nullable', 'string', 'max:255', 'regex:/^(https?:\/\/)?[^\s\/]+\.[^\s\/]+(\/\S*)?$/i'],
            'industry' => ['nullable', 'string', 'max:255'],
            'size_band' => ['nullable', 'string', 'max:30'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'gstin' => ['nullable', 'regex:/^[0-9A-Z]{15}$/'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'email:rfc', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'designation' => ['nullable', 'string', 'max:255'],
            'org_role' => ['nullable', 'in:'.implode(',', array_column(OrgRole::cases(), 'value'))],
            'zoho_crm_id' => ['nullable', 'string', 'max:255'],
        ], [
            'gstin.regex' => 'The GSTIN must be 15 letters and digits.',
            'website.regex' => 'The website does not look like an address.',
        ]);

        if ($validator->fails()) {
            return RowResult::failed($validator->errors()->all());
        }

        $website = $this->normalizeWebsite($row['website']);
        $key = $this->organizationKey($row['gstin'], $row['organization_name'], $website);

        $organization = $this->findOrganization($row['gstin'], $row['organization_name'], $website);

        if ($organization instanceof RowResult) {
            return $organization;
        }

        $known = $this->organizations[$key] ?? null;

        if ($known === null && $organization !== null) {
            $known = $this->organizations[$key] = ['id' => $organization->getKey(), 'has_owner' => $this->hasOwner($organization)];
        }

        $existing = User::withTrashed()->where('email', $row['contact_email'])->first();

        if ($existing !== null || isset($this->seenEmails[$row['contact_email']])) {
            return $this->existingContact($existing, $organization, $key, $row['contact_email']);
        }

        $role = $row['org_role'] !== '' ? OrgRole::from($row['org_role']) : (($known['has_owner'] ?? false) ? OrgRole::Manager : OrgRole::Owner);

        $this->seenEmails[$row['contact_email']] = $key;
        $this->organizations[$key] = ['id' => $known['id'] ?? null, 'has_owner' => ($known['has_owner'] ?? false) || $role === OrgRole::Owner];

        if ($this->commit) {
            $this->create($row, $website, $key, $organization, $role, $line);
        }

        return RowResult::created();
    }

    /** A contact whose email already has an account: skipped when they are already in this organization, refused otherwise. */
    private function existingContact(?User $existing, ?Organization $organization, string $key, string $email): RowResult
    {
        if ($existing === null) {
            // Seen earlier in this same file.
            return $this->seenEmails[$email] === $key
                ? RowResult::skipped('This email is already listed for this organization.')
                : RowResult::failed(['This email is listed under another organization earlier in the file.']);
        }

        if ($existing->role !== UserRole::Client) {
            return RowResult::failed(['This email belongs to a '.$existing->role->value.' account, not a client.']);
        }

        if ($organization !== null && $existing->organizations()->whereKey($organization->getKey())->exists()) {
            return RowResult::skipped('This person is already in the organization.');
        }

        return RowResult::failed(['This email already belongs to a client of another organization.']);
    }

    /** @return Organization|RowResult|null the matching organization, a refusal, or null when the organization is new */
    private function findOrganization(string $gstin, string $name, ?string $website): Organization|RowResult|null
    {
        $byGstin = $gstin === '' ? null : Organization::withTrashed()->where('gstin', $gstin)->first();
        $byName = Organization::withTrashed()
            ->whereRaw('lower(name) = ?', [Str::lower($name)])
            ->get()
            ->first(fn (Organization $organization): bool => $this->sameSite($organization->website, $website));

        if ($byGstin !== null && $byName !== null && ! $byGstin->is($byName)) {
            return RowResult::failed(['The GSTIN matches one organization and the name and website match another.']);
        }

        $match = $byGstin ?? $byName;

        if ($match?->trashed()) {
            return RowResult::failed(['The matching organization is archived. Restore it first.']);
        }

        return $match;
    }

    private function hasOwner(Organization $organization): bool
    {
        return $organization->users()->wherePivot('org_role', OrgRole::Owner->value)->exists();
    }

    /** @param  array<string, string>  $row */
    private function create(array $row, ?string $website, string $key, ?Organization $organization, OrgRole $role, int $line): void
    {
        DB::transaction(function () use ($row, $website, $key, $organization, $role, $line): void {
            $organization ??= $this->createOrganization($row, $website, $line);
            $this->organizations[$key]['id'] = $organization->getKey();

            $user = User::query()->create([
                'name' => $row['contact_name'],
                'email' => $row['contact_email'],
                'role' => UserRole::Client,
                'status' => UserStatus::Pending,
            ]);
            $user->assignRole(UserRole::Client->value);

            Profile::query()->create([
                'user_id' => $user->getKey(),
                'phone' => $row['contact_phone'] ?: null,
                'onboarding_state' => OnboardingState::Registered,
            ]);

            $organization->users()->attach($user->getKey(), ['org_role' => $role->value, 'designation' => $row['designation'] ?: null]);

            $this->logCreated($user, $line);
        });
    }

    /** @param  array<string, string>  $row */
    private function createOrganization(array $row, ?string $website, int $line): Organization
    {
        $organization = Organization::query()->create([
            'name' => $row['organization_name'],
            'slug' => $this->uniqueSlug($row['organization_name']),
            'website' => $website,
            'industry' => $row['industry'] ?: null,
            'size_band' => $row['size_band'] ?: null,
            'city' => $row['city'] ?: null,
            'state' => $row['state'] ?: null,
            'gstin' => $row['gstin'] ?: null,
            'zoho_crm_id' => $row['zoho_crm_id'] ?: null,
            'status' => OrganizationStatus::Pending,
            'created_by' => $this->actorId(),
        ]);

        $this->logCreated($organization, $line);

        return $organization;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'organization';
        $slug = $base;

        for ($i = 2; Organization::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    private function normalizeWebsite(string $website): ?string
    {
        $website = trim($website);

        if ($website === '') {
            return null;
        }

        return preg_match('~^https?://~i', $website) === 1 ? $website : 'https://'.$website;
    }

    /** Two websites are the same when their hosts match, ignoring scheme, "www." and case. Two missing websites match. */
    private function sameSite(?string $a, ?string $b): bool
    {
        return $this->host($a) === $this->host($b);
    }

    private function host(?string $website): ?string
    {
        if ($website === null || trim($website) === '') {
            return null;
        }

        $host = parse_url(preg_match('~^https?://~i', $website) === 1 ? $website : 'https://'.$website, PHP_URL_HOST);

        return is_string($host) ? Str::of($host)->lower()->replaceStart('www.', '')->toString() : null;
    }

    private function organizationKey(string $gstin, string $name, ?string $website): string
    {
        return $gstin !== '' ? 'gstin:'.$gstin : 'name:'.Str::lower($name).'|'.($this->host($website) ?? '');
    }
}
