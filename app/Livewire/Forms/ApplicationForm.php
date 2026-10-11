<?php

namespace App\Livewire\Forms;

use Livewire\Form;

/** The apply form of spec 11. Proof-of-work selection joins it with the proof model in Pass 6. */
class ApplicationForm extends Form
{
    public string $motivation = '';

    public string $relevant_experience = '';

    public string $availability_note = '';

    public string $expected_amount = '';

    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'motivation' => ['required', 'string', 'min:20', 'max:3000'],
            'relevant_experience' => ['nullable', 'string', 'max:3000'],
            'availability_note' => ['nullable', 'string', 'max:1000'],
            'expected_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'motivation' => 'motivation',
            'relevant_experience' => 'relevant experience',
            'availability_note' => 'availability',
            'expected_amount' => 'expected amount',
        ];
    }

    /** @return array{motivation: string, relevant_experience: string|null, availability_note: string|null, expected_amount: string|null} */
    public function payload(): array
    {
        return [
            'motivation' => trim($this->motivation),
            'relevant_experience' => filled($this->relevant_experience) ? trim($this->relevant_experience) : null,
            'availability_note' => filled($this->availability_note) ? trim($this->availability_note) : null,
            'expected_amount' => filled($this->expected_amount) ? $this->expected_amount : null,
        ];
    }
}
