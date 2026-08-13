<?php

namespace App\Http\Requests\InboundAttendance;

use App\Enums\InboundAttendanceProfileStatus;
use App\Enums\InboundOpeningMode;
use App\Models\InboundAttendanceProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class InboundAttendanceProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('inbound_attendance.manage_profiles');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::enum(InboundAttendanceProfileStatus::class)],
            'is_fallback' => ['boolean'],
            'match_expressions' => ['nullable', 'string', 'max:4000'],
            'match_priority' => ['required', 'integer', 'min:1', 'max:9999'],
            'conversation_flow_id' => ['required', 'integer', 'exists:conversation_flows,id'],
            'opening_mode' => ['required', Rule::enum(InboundOpeningMode::class)],
            'presentation_text' => ['nullable', 'string', 'max:4000'],
            'window_start' => ['nullable', 'date_format:H:i'],
            'window_end' => ['nullable', 'date_format:H:i'],
            'daily_start_limit' => ['required', 'integer', 'min:0', 'max:10000'],
            'homologation_threshold' => ['required', 'integer', 'min:0', 'max:1000'],
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
            'is_fallback' => 'atender o que sobrou',
            'match_expressions' => 'expressões',
            'match_priority' => 'ordem de avaliação',
            'conversation_flow_id' => 'fluxo conversacional',
            'opening_mode' => 'modo de abertura',
            'presentation_text' => 'texto de apresentação',
            'window_start' => 'início da janela',
            'window_end' => 'fim da janela',
            'daily_start_limit' => 'teto diário',
            'homologation_threshold' => 'conversas para homologar',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ativo = $this->enum('status', InboundAttendanceProfileStatus::class) === InboundAttendanceProfileStatus::Active;
            $fallback = $this->boolean('is_fallback');

            /*
             | Perfil de fallback é obrigatório, e é único.
             |
             | Roteamento por expressão erra sempre para o mesmo lado: alguém
             | escreve algo que nenhuma regra previu. Sem destino para o resto,
             | essa pessoa fica sem resposta justamente por ter escrito algo
             | fora do script — e ninguém descobre, porque não há erro nenhum,
             | só silêncio.
             */
            if ($ativo && ! $fallback && ! $this->outroFallbackAtivo()) {
                $validator->errors()->add('is_fallback', 'Nenhum perfil ativo está marcado para atender o que sobrou. Marque este ou marque outro antes.');
            }

            if ($fallback && $this->outroFallbackAtivo()) {
                $validator->errors()->add('is_fallback', 'Já existe um perfil ativo marcado para atender o que sobrou. Desmarque o outro primeiro.');
            }

            // Perfil sem expressão e sem a marca de fallback nunca atende
            // ninguém: nasce morto e ninguém percebe.
            if ($ativo && ! $fallback && trim((string) $this->input('match_expressions')) === '') {
                $validator->errors()->add('match_expressions', 'Informe ao menos uma expressão, ou marque o perfil para atender o que sobrou.');
            }

            // Meia janela é janela nenhuma, e a metade que falta cairia no
            // padrão global sem ninguém pedir.
            $inicio = (string) $this->input('window_start');
            $fim = (string) $this->input('window_end');

            if (($inicio === '') !== ($fim === '')) {
                $validator->errors()->add('window_end', 'Informe os dois horários da janela, ou nenhum para usar a janela geral.');
            }

            if ($this->enum('opening_mode', InboundOpeningMode::class) === InboundOpeningMode::SurveyOnly
                && trim((string) $this->input('presentation_text')) === '') {
                $validator->errors()->add('presentation_text', 'No modo que só apresenta a pesquisa, o texto de apresentação é a mensagem inteira.');
            }
        });
    }

    private function outroFallbackAtivo(): bool
    {
        return InboundAttendanceProfile::query()
            ->where('is_fallback', true)
            ->where('status', InboundAttendanceProfileStatus::Active)
            ->when($this->route('profile'), fn ($query, $profile) => $query->whereKeyNot($profile->id ?? $profile))
            ->exists();
    }
}
