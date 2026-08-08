<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class MetaSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('whatsapp.meta.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'base_url' => ['nullable', 'string', 'max:255', 'url'],
            'api_version' => ['nullable', 'string', 'max:20', 'regex:/^v\d+\.\d+$/'],

            /*
             | O identificador do número é numérico e vem do painel da Meta. Não
             | é o telefone: quem digita o telefone aqui recebe erro de
             | autenticação genérico lá na frente, e é isso que a mensagem
             | própria abaixo evita.
             */
            'phone_number_id' => ['nullable', 'string', 'max:40', 'regex:/^\d+$/'],
            'business_account_id' => ['nullable', 'string', 'max:40', 'regex:/^\d+$/'],

            'token' => ['nullable', 'string', 'max:1000'],
            'forget_token' => ['nullable', 'boolean'],
            'app_secret' => ['nullable', 'string', 'max:500'],
            'forget_app_secret' => ['nullable', 'boolean'],
            'verify_token' => ['nullable', 'string', 'max:190'],

            /*
             | Nome de template da Meta: minúsculas, dígitos e sublinhado. Ela
             | recusa qualquer outra coisa, e recusa com erro genérico.
             */
            'invite_template' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9_]+$/'],
            'invite_language' => ['nullable', 'string', 'max:10'],

            'timeout' => ['nullable', 'integer', 'min:1', 'max:300'],
            'connect_timeout' => ['nullable', 'integer', 'min:1', 'max:60'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'base_url.url' => 'Informe uma URL completa, começando por http ou https.',
            'api_version.regex' => 'A versão da API tem o formato v21.0.',
            'phone_number_id.regex' => 'O identificador do número é só de dígitos, e não é o telefone. Copie do painel da Meta.',
            'business_account_id.regex' => 'O identificador da conta é só de dígitos. Copie do painel da Meta.',
            'invite_template.regex' => 'O nome do template aceita apenas minúsculas, dígitos e sublinhado, como convite_pergunta_unica.', // ortografia:ignorar - identificador de template da Meta, que recusa acento
        ];
    }
}
