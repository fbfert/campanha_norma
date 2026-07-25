<?php

namespace App\Http\Requests\WhatsApp;

use Illuminate\Foundation\Http\FormRequest;

class ClearWhatsAppSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('whatsapp.connection.clear_session') ?? false;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password'],
            'confirmation' => ['required', 'in:EXCLUIR SESSAO'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirmation.in' => 'Digite exatamente EXCLUIR SESSAO para confirmar.',
            'current_password.current_password' => 'A senha atual nao confere.',
        ];
    }
}
