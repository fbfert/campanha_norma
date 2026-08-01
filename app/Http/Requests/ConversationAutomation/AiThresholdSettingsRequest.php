<?php

namespace App\Http\Requests\ConversationAutomation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AiThresholdSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('conversation_automation.manage_settings') ?? false;
    }

    public function rules(): array
    {
        $faixa = ['required', 'numeric', 'min:0', 'max:1'];

        return [
            'ai_min_classification_confidence' => $faixa,
            'ai_min_extraction_confidence' => $faixa,
            'ai_response_min_confidence' => $faixa,
            'ai_response_auto_send_min_confidence' => $faixa,
            'analytics_low_confidence_threshold' => $faixa,
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Autoenviar com confiança menor do que a exigida para nem
            // sinalizar revisão seria enviar sozinho um texto que o próprio
            // sistema considera duvidoso.
            $revisao = (float) $this->input('ai_response_min_confidence');
            $autoenvio = (float) $this->input('ai_response_auto_send_min_confidence');

            if ($autoenvio < $revisao) {
                $validator->errors()->add(
                    'ai_response_auto_send_min_confidence',
                    'O limiar de autoenvio não pode ser menor que o de revisão obrigatória.'
                );
            }
        });
    }

    public function attributes(): array
    {
        return [
            'ai_min_classification_confidence' => 'confiança mínima da classificação',
            'ai_min_extraction_confidence' => 'confiança mínima da extração',
            'ai_response_min_confidence' => 'confiança mínima da resposta',
            'ai_response_auto_send_min_confidence' => 'confiança mínima para autoenvio',
            'analytics_low_confidence_threshold' => 'limiar de baixa confiança nos relatórios',
        ];
    }
}
