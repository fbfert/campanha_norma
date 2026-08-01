<?php

namespace App\Http\Requests\ConversationAutomation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ConversationAutomationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('conversation_automation.manage_settings') ?? false;
    }

    public function rules(): array
    {
        return [
            'enabled' => ['nullable', 'boolean'],
            'auto_send_enabled' => ['nullable', 'boolean'],
            'mark_do_not_contact_on_refusal' => ['nullable', 'boolean'],
            'max_automated_messages' => ['required', 'integer', 'min:1', 'max:10'],
            'default_validity_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'short_answer_max_words' => ['required', 'integer', 'min:1', 'max:50'],
            'min_response_interval_seconds' => ['required', 'integer', 'min:0', 'max:3600'],
            'window_start' => ['required', 'date_format:H:i'],
            'window_end' => ['required', 'date_format:H:i'],
            'transparency_mode' => ['required', Rule::in(['none', 'prefix', 'suffix'])],
            'transparency_text' => ['nullable', 'string', 'max:500'],
            'ambiguous_behavior' => ['required', Rule::in(['waiting_human', 'keep_waiting'])],
            'no_question_behavior' => ['required', Rule::in(['waiting_human', 'completed'])],
            'thank_you_text' => ['required', 'string', 'max:1000'],
            'permission_denied_text' => ['required', 'string', 'max:1000'],
            'opt_out_text' => ['required', 'string', 'max:1000'],
            'yes_expressions' => ['required', 'string', 'max:4000'],
            'no_expressions' => ['required', 'string', 'max:4000'],
            'opt_out_expressions' => ['required', 'string', 'max:4000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Envio automático com o motor desligado não envia nada: seria uma
            // tela dizendo que responde sozinha enquanto o guard nega tudo.
            if ($this->boolean('auto_send_enabled') && ! $this->boolean('enabled')) {
                $validator->errors()->add('auto_send_enabled', 'O envio automático exige a automação ligada.');
            }

            // Transparência escolhida sem texto não avisa ninguém.
            if ($this->input('transparency_mode') !== 'none' && blank($this->input('transparency_text'))) {
                $validator->errors()->add('transparency_text', 'Informe o texto do aviso de automação ou escolha o modo "sem aviso".');
            }
        });
    }

    public function messages(): array
    {
        return [
            'window_start.date_format' => 'Informe a hora inicial no formato HH:MM.',
            'window_end.date_format' => 'Informe a hora final no formato HH:MM.',
        ];
    }
}
