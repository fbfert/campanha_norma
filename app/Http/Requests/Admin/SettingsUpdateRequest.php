<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-settings') ?? false;
    }

    public function rules(): array
    {
        return [
            'system.name' => ['required', 'string', 'max:120'],
            'system.timezone' => ['required', 'string', 'timezone'],
            'system.date_format' => ['required', 'string', 'max:20'],
            'system.datetime_format' => ['required', 'string', 'max:30'],
            'system.records_per_page' => ['required', 'integer', 'min:5', 'max:100'],
        ];
    }
}
