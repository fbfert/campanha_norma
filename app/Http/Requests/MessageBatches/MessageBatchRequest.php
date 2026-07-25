<?php

namespace App\Http\Requests\MessageBatches;

use App\Enums\MessageBatchSelectionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MessageBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('message_batch') ? $this->user()?->can('message_batches.update') : $this->user()?->can('message_batches.create');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:1000'],
            'message_template_id' => ['nullable', 'integer', 'exists:message_templates,id'],
            'message_body' => ['nullable', 'required_without:message_template_id', 'string', 'max:4096'],
            'selection_type' => ['required', Rule::enum(MessageBatchSelectionType::class)],
            'contact_ids' => ['nullable', 'array'],
            'contact_ids.*' => ['integer', 'exists:contacts,id'],
            'random_quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'filters' => ['nullable', 'array'],
        ];
    }
}
