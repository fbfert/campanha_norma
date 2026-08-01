<?php

namespace App\Services\ConversationAutomation;

use App\Models\SystemSetting;
use App\Services\SystemSettingService;

/**
 * Configuração da automação conversacional pela tela.
 *
 * As chaves já existiam em `system_settings`, gravadas pelo seeder e alteráveis
 * apenas por quem tem acesso ao banco. Isso deixava a decisão mais sensível do
 * sistema — ligar o envio automático — fora do alcance de quem responde por
 * ela, e sem registro de quem ligou.
 *
 * `SystemSettingService::updateMany` não serve aqui: ele marca tudo como
 * público e adivinha o tipo. Este serviço grava com grupo, tipo e visibilidade
 * declarados, preservando o que o seeder definiu.
 */
class ConversationAutomationSettings
{
    public const GROUP = 'conversation_automation';

    /**
     * Tipo declarado de cada chave editável, no mesmo vocabulário do seeder.
     *
     * As chaves de fila (`queue` e `send_queue`) ficam deliberadamente de fora:
     * um nome de fila digitado errado não dá erro, apenas manda o trabalho para
     * uma fila que nenhum worker consome, e a automação emudece sem sintoma.
     * Trocar fila continua exigindo deploy, junto com o worker que a consome.
     */
    private const SCHEMA = [
        'enabled' => 'boolean',
        'auto_send_enabled' => 'boolean',
        'mark_do_not_contact_on_refusal' => 'boolean',
        'max_automated_messages' => 'integer',
        'default_validity_hours' => 'integer',
        'short_answer_max_words' => 'integer',
        'min_response_interval_seconds' => 'integer',
        'window_start' => 'string',
        'window_end' => 'string',
        'transparency_mode' => 'string',
        'transparency_text' => 'string',
        'ambiguous_behavior' => 'string',
        'no_question_behavior' => 'string',
        'thank_you_text' => 'string',
        'permission_denied_text' => 'string',
        'opt_out_text' => 'string',
        'yes_expressions' => 'string',
        'no_expressions' => 'string',
        'opt_out_expressions' => 'string',
    ];

    /** Chaves guardadas como lista separada por barra vertical. */
    private const LISTS = ['yes_expressions', 'no_expressions', 'opt_out_expressions'];

    public function __construct(private readonly SystemSettingService $settings) {}

    /**
     * Estado atual para a tela. Listas voltam com uma expressão por linha,
     * porque revisar trinta expressões separadas por barra numa linha só e
     * como não revisar.
     *
     * @return array<string, mixed>
     */
    public function forForm(): array
    {
        $form = [];

        foreach (array_keys(self::SCHEMA) as $key) {
            $value = (string) $this->settings->get(self::GROUP.'.'.$key, '');

            $form[$key] = in_array($key, self::LISTS, true)
                ? implode("\n", $this->splitList($value))
                : $value;
        }

        return $form;
    }

    /**
     * Grava o formulário e devolve o estado anterior para a auditoria.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    public function save(array $values): array
    {
        $previous = $this->auditable();

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::SCHEMA)) {
                continue;
            }

            SystemSetting::query()->updateOrCreate(
                ['key' => self::GROUP.'.'.$key],
                [
                    'group' => self::GROUP,
                    'value' => $this->normalize($key, $value),
                    'type' => self::SCHEMA[$key],
                    'is_public' => false,
                ]
            );
        }

        $this->settings->forget();

        return $previous;
    }

    /**
     * Estado atual em formato plano, para o log de auditoria.
     *
     * @return array<string, string>
     */
    public function auditable(): array
    {
        $flat = [];

        foreach (array_keys(self::SCHEMA) as $key) {
            $flat[$key] = (string) $this->settings->get(self::GROUP.'.'.$key, '');
        }

        return $flat;
    }

    /**
     * Quebra a lista guardada em expressões, sem vazio e sem duplicata.
     *
     * @return list<string>
     */
    public function splitList(string $value): array
    {
        return collect(preg_split('/[|\r\n]+/', $value) ?: [])
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalize(string $key, mixed $value): string
    {
        if (in_array($key, self::LISTS, true)) {
            return implode('|', $this->splitList((string) $value));
        }

        return match (self::SCHEMA[$key]) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'integer' => (string) (int) $value,
            default => trim((string) $value),
        };
    }
}
