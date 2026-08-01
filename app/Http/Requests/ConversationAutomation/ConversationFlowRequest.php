<?php

namespace App\Http\Requests\ConversationAutomation;

use App\Enums\ConversationFlowStatus;
use App\Enums\ConversationQuestionOrder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConversationFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('conversation_automation.manage_flows');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', Rule::enum(ConversationFlowStatus::class)],
            'presentation_template_id' => ['nullable', 'exists:message_templates,id'],
            'presentation_text' => ['nullable', 'string', 'max:4096'],
            'thank_you_text' => ['nullable', 'string', 'max:4096'],
            'permission_denied_text' => ['nullable', 'string', 'max:4096'],
            'max_main_questions' => ['required', 'integer', 'min:1', 'max:10'],
            // Ausente mantem o que o fluxo já tem, ou o padrão `sorteio` num
            // fluxo novo. Exigir aqui quebraria todo chamador que não conhece
            // um campo criado depois dele.
            'question_order' => ['sometimes', 'required', Rule::enum(ConversationQuestionOrder::class)],
            'max_followups' => ['required', 'integer', 'min:0', 'max:10'],
            'validity_hours' => ['required', 'integer', 'min:1', 'max:8760'],
            'transparency_enabled' => ['nullable', 'boolean'],
            'transparency_text' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
