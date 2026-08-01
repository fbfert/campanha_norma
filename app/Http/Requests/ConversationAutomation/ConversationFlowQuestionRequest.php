<?php

namespace App\Http\Requests\ConversationAutomation;

use App\Services\Placeholders\PlaceholderParserService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Placeholder inexistente não falha no envio: ele sai literal para
            // o contato, e ninguém percebe até alguém receber "{cidde}".
            [, $errors] = app(PlaceholderParserService::class)->validate((string) $this->input('text'));

            foreach ($errors as $error) {
                $validator->errors()->add('text', $error);
            }
        });
    }
}
