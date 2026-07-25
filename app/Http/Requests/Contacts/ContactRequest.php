<?php

namespace App\Http\Requests\Contacts;

use App\Enums\ConsentStatus;
use App\Enums\ContactSource;
use App\Enums\ContactStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('contact') ? $this->user()?->can('contacts.update') : $this->user()?->can('contacts.create');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'size:2'],
            'country' => ['nullable', 'string', 'size:2'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', Rule::enum(ContactStatus::class)],
            'source' => ['required', Rule::enum(ContactSource::class)],
            'consent_status' => ['required', Rule::enum(ConsentStatus::class)],
            'consent_source' => ['nullable', 'string', 'max:255'],
            'consent_text' => ['nullable', 'string', 'max:5000'],
            'consent_at' => ['nullable', 'date'],
            'do_not_contact' => ['nullable', 'boolean'],
            'do_not_contact_reason' => ['nullable', 'string', 'max:1000'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer', 'exists:tags,id'],
        ];
    }
}
