<?php

namespace App\Http\Requests\ConversationAutomation;

use Illuminate\Foundation\Http\FormRequest;

class ConversationFlowQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('conversation_automation.manage_questions');
    }

    public function rules(): array
    {
        return [
            'internal_title' => ['required', 'string', 'max:150'],
            'text' => ['required', 'string', 'max:4096'],
            'category' => ['nullable', 'string', 'max:100'],
            'weight' => ['required', 'integer', 'min:1', 'max:1000'],
            'display_order' => ['required', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
