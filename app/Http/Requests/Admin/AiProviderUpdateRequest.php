<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiProviderUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ai.provider.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $providers = array_keys((array) config('ai.catalog', []));

        return [
            'provider' => ['nullable', Rule::in($providers)],
            'url' => ['nullable', 'required_with:provider', 'string', 'max:255', 'url'],
            'model' => ['nullable', 'required_with:provider', 'string', 'max:190'],
            'organization' => ['nullable', 'string', 'max:190'],
            'key' => ['nullable', 'string', 'max:500'],
            'forget_key' => ['nullable', 'boolean'],

            'timeout' => ['required', 'integer', 'min:1', 'max:300'],
            'connect_timeout' => ['required', 'integer', 'min:1', 'max:60'],
            'max_output_tokens' => ['required', 'integer', 'min:64', 'max:32000'],
            'temperature' => ['required', 'numeric', 'min:0', 'max:2'],
            'cost_input_per_1k' => ['nullable', 'numeric', 'min:0'],
            'cost_output_per_1k' => ['nullable', 'numeric', 'min:0'],

            'embedding_provider' => ['nullable', Rule::in($providers)],
            'embedding_url' => ['nullable', 'required_with:embedding_provider', 'string', 'max:255', 'url'],
            'embedding_model' => ['nullable', 'required_with:embedding_provider', 'string', 'max:190'],
            'embedding_dimensions' => ['nullable', 'required_with:embedding_provider', 'integer', 'min:8', 'max:16383'],
            'embedding_key' => ['nullable', 'string', 'max:500'],
            'forget_embedding_key' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'url.url' => 'Informe uma URL completa, começando por http ou https.',
            'url.required_with' => 'Escolhido um fornecedor, a URL da API e obrigatória.',
            'model.required_with' => 'Escolhido um fornecedor, o modelo e obrigatório.',
            // O teto vem do tamanho da coluna que guarda o vetor, medido na
            // ADR 0001. Passar disso trunca o embedding em silêncio.
            'embedding_dimensions.max' => 'O limite da coluna de vetores e 16383 dimensões.',
        ];
    }
}
