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
            'is_campaign' => ['nullable', 'boolean'],
            'conversation_flow_id' => ['nullable', 'integer', 'exists:conversation_flows,id'],
            'message_template_id' => ['nullable', 'integer', 'exists:message_templates,id'],
            'message_template_ids' => ['nullable', 'array', 'min:1', 'max:10', 'required_if:is_campaign,1'],
            'message_template_ids.*' => ['integer', 'distinct', 'exists:message_templates,id'],
            'message_body' => ['nullable', 'required_without_all:message_template_id,message_template_ids', 'string', 'max:4096'],
            'selection_type' => ['required', Rule::enum(MessageBatchSelectionType::class)],
            'contact_ids' => ['nullable', 'array'],
            'contact_ids.*' => ['integer', 'exists:contacts,id'],
            'random_quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'random_seed' => ['nullable', 'string', 'max:64'],
            'filters' => ['nullable', 'array'],
        ];
    }
}
