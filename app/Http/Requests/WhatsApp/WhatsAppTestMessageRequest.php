<?php

namespace App\Http\Requests\WhatsApp;

use Illuminate\Foundation\Http\FormRequest;

class WhatsAppTestMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('whatsapp.test_message.send') ?? false;
    }

    public function rules(): array
    {
        return [
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'message' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'contact_id.required' => 'Selecione um contato.',
            'message.required' => 'Informe a mensagem de teste.',
            'message.max' => 'A mensagem de teste pode ter no maximo 1000 caracteres.',
        ];
    }
}
