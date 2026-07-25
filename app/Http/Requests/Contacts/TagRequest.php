<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('contacts.manage_tags') ?? false;
    }

    public function rules(): array
    {
        $tag = $this->route('tag');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('tags', 'name')->ignore($tag)->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:1000'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
