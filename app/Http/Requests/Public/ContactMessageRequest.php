<?php

namespace App\Http\Requests\Public;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The public contact form (spec 10.2). `company_website` is the honeypot: it is hidden from people, so only a bot
 * fills it in. Names and subjects may not hold line breaks, which keeps them safe to use in an email header.
 */
class ContactMessageRequest extends FormRequest
{
    public const HONEYPOT = 'company_website';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', 'regex:/^[^\r\n]+$/'],
            'email' => ['required', 'string', 'email:rfc', 'max:190'],
            'subject' => ['nullable', 'string', 'max:160', 'regex:/^[^\r\n]*$/'],
            'message' => ['required', 'string', 'min:10', 'max:4000'],
            self::HONEYPOT => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.regex' => 'The name cannot contain line breaks.',
            'subject.regex' => 'The subject cannot contain line breaks.',
        ];
    }

    /** True when a bot filled the hidden field. */
    public function isBot(): bool
    {
        return filled($this->input(self::HONEYPOT));
    }
}
