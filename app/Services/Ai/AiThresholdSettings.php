<?php

namespace App\Services\Ai;

use App\Models\SystemSetting;
use App\Services\SystemSettingService;

/**
 * Limiares de confiança da IA.
 *
 * São cinco números que decidem coisas diferentes, e que viviam só no banco:
 * mudar qualquer um deles exigia acesso ao servidor, e nada registrava quem
 * mudou. Sendo os números que decidem se um texto sai sem revisão humana, ficar
 * fora de tela e fora de auditoria e o pior dos dois mundos.
 *
 * Confiança e o modelo avaliando a si mesmo, e ele erra para cima. O limiar
 * filtra o descarado, não o plausível e errado — por isso a tela explica a
 * faixa de cada número em vez de so oferecer um campo.
 */
class AiThresholdSettings
{
    /**
     * Chave completa => grupo em que ela e gravada.
     *
     * O grupo sai do prefixo, e não de um palpite: `analytics.*` não pertence
     * ao grupo `ai` so por tratar de confiança.
     */
    private const SCHEMA = [
        'ai.min_classification_confidence' => 'ai',
        'ai.min_extraction_confidence' => 'ai',
        'ai.response.min_confidence' => 'ai',
        'ai.response.auto_send_min_confidence' => 'ai',
        'ai.response.safety_net_min_confidence' => 'ai',
        'analytics.low_confidence_threshold' => 'analytics',
    ];

    public function __construct(private readonly SystemSettingService $settings) {}

    /**
     * Estado atual, com a chave achatada para caber no formulário.
     *
     * @return array<string, string>
     */
    public function forForm(): array
    {
        $form = [];

        foreach (array_keys(self::SCHEMA) as $key) {
            $form[$this->field($key)] = (string) $this->settings->get($key, '');
        }

        return $form;
    }

    /**
     * @param  array<string, mixed>  $values  Indexado pelo nome do campo.
     * @return array<string, string> valores anteriores, para auditoria
     */
    public function save(array $values): array
    {
        $previous = $this->auditable();

        foreach (self::SCHEMA as $key => $group) {
            $field = $this->field($key);

            if (! array_key_exists($field, $values)) {
                continue;
            }

            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'group' => $group,
                    // Guardado com duas casas: confiança de modelo não tem
                    // precisão que justifique mais, e "0.9" e "0.90" na mesma
                    // base confundem quem compara.
                    'value' => number_format((float) $values[$field], 2, '.', ''),
                    'type' => 'string',
                    'is_public' => false,
                ]
            );
        }

        $this->settings->forget();

        return $previous;
    }

    /** @return array<string, string> */
    public function auditable(): array
    {
        return $this->forForm();
    }

    /**
     * Nome do campo no formulário: a chave sem ponto, que o HTML aceita.
     */
    public function field(string $key): string
    {
        return str_replace('.', '_', $key);
    }
}
