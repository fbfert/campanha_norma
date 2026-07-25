<?php

namespace App\Http\Requests\MessageTemplates;

use App\Enums\MessageTemplateStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MessageTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('message_template') ? $this->user()?->can('message_templates.update') : $this->user()?->can('message_templates.create');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'body' => ['required', 'string', 'max:4096'],
            'status' => ['required', Rule::enum(MessageTemplateStatus::class)],
        ];
    }
}
