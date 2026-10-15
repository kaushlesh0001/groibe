<?php

namespace App\Livewire\Forms;

use App\Models\Organization;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Form;

/** The organization profile a client owner or manager edits (spec 7.1, 12). */
class OrganizationProfileForm extends Form
{
    /** Locked: it decides which organization the GSTIN uniqueness rule ignores, so the browser must not be able to change it. */
    #[Locked]
    public ?int $organizationId = null;

    public string $name = '';

    public string $legal_name = '';

    public string $gstin = '';

    public string $website = '';

    public string $industry = '';

    public string $size_band = '';

    public string $description = '';

    public string $city = '';

    public string $state = '';

    public string $country = 'India';

    public function fillFrom(Organization $organization): void
    {
        $this->organizationId = $organization->getKey();

        foreach (['name', 'legal_name', 'gstin', 'website', 'industry', 'size_band', 'description', 'city', 'state', 'country'] as $field) {
            $this->{$field} = (string) $organization->{$field};
        }
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'gstin' => ['nullable', 'regex:/^[0-9A-Za-z]{15}$/', Rule::unique('organizations', 'gstin')->ignore($this->organizationId)],
            'website' => ['nullable', 'url', 'max:255'],
            'industry' => ['nullable', 'string', 'max:255'],
            'size_band' => ['nullable', Rule::in(config('groibe.organizations.size_bands'))],
            'description' => ['nullable', 'string', 'max:2000'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'gstin.regex' => 'The GSTIN must be 15 letters and digits.',
            'gstin.unique' => 'This GSTIN is already registered with Groibe. Contact Groibe if it is yours.',
        ];
    }
}
