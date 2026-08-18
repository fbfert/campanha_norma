<?php

namespace App\Http\Requests\KeywordCampaigns;

use App\Enums\KeywordCampaignStatus;
use App\Services\KeywordCampaigns\KeywordListNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class KeywordCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('keyword_campaigns.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::enum(KeywordCampaignStatus::class)],
            'conversation_flow_id' => ['nullable', 'integer', 'exists:conversation_flows,id'],
            'keywords' => ['required', 'string', 'max:4000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'participant_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'hourly_alert_threshold' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'confirmation_text' => ['required', 'string', 'max:4000'],
            'already_enrolled_text' => ['required', 'string', 'max:4000'],
            'survey_invite_text' => ['nullable', 'string', 'max:4000'],
            'out_of_window_text' => ['nullable', 'string', 'max:4000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'description' => 'descrição',
            'status' => 'situação',
            'conversation_flow_id' => 'fluxo conversacional',
            'keywords' => 'palavras-chave',
            'starts_at' => 'início da vigência',
            'ends_at' => 'fim da vigência',
            'participant_limit' => 'limite de participantes',
            'hourly_alert_threshold' => 'alarme por hora',
            'confirmation_text' => 'texto de confirmação',
            'already_enrolled_text' => 'texto de já inscrito',
            'survey_invite_text' => 'convite da pesquisa',
            'out_of_window_text' => 'texto de fora da vigência',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $palavras = app(KeywordListNormalizer::class)->normalizar($this->input('keywords'));

            /*
             | Lista vazia depois de normalizar.
             |
             | O campo não estava em branco — a validação de `required` já teria
             | pegado isso. Aqui é o caso de alguém ter digitado só pontuação ou
             | só emoji, que a normalização descarta e deixaria uma campanha
             | ativa que nunca casa com nada.
             */
            if ($palavras === []) {
                $validator->errors()->add('keywords', 'Nenhuma palavra utilizável sobrou depois de normalizar. Use letras e números.');
            }
        });
    }

    /**
     * A lista já normalizada, como o banco guarda.
     *
     * @return list<string>
     */
    public function palavrasNormalizadas(): array
    {
        return app(KeywordListNormalizer::class)->normalizar($this->input('keywords'));
    }
}
