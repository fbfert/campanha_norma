<?php

namespace App\Services\ResponseGeneration;

use App\Services\ConversationAutomation\PermissionResponseClassifier;
use App\Services\SystemSettingService;

/**
 * Validacao deterministica do texto gerado.
 *
 * Roda depois do modelo, sempre. O prompt tambem pede estas regras, mas prompt
 * e pedido e validador e garantia: um texto reprovado aqui nunca e enviado
 * automaticamente, independentemente do que o modelo tenha reportado.
 */
class ReplyTextValidator
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly PermissionResponseClassifier $normalizer,
    ) {}

    /**
     * @return array{valid: bool, errors: array<int, string>}
     */
    public function validate(?string $text): array
    {
        $errors = [];
        $raw = trim((string) $text);

        if ($raw === '') {
            return ['valid' => false, 'errors' => ['texto_vazio']];
        }

        $max = max(50, (int) $this->settings->get('ai.response.max_text_length', 500));
        if (mb_strlen($raw) > $max) {
            $errors[] = 'texto_muito_longo';
        }

        // No maximo uma pergunta: mais de um ponto de interrogacao indica que o
        // modelo empilhou perguntas, o que reduz a taxa de resposta e confunde.
        if (mb_substr_count($raw, '?') > 1) {
            $errors[] = 'mais_de_uma_pergunta';
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $maxLines = max(1, (int) $this->settings->get('ai.response.max_lines', 4));
        if (count($lines) > $maxLines) {
            $errors[] = 'mensagem_longa_demais';
        }

        $normalized = $this->normalizer->normalize($raw);

        foreach ($this->forbiddenGroups() as $group => $expressions) {
            if ($this->matches($normalized, $expressions)) {
                $errors[] = $group;
            }
        }

        return ['valid' => $errors === [], 'errors' => array_values(array_unique($errors))];
    }

    /**
     * Grupos de expressoes proibidas, configuraveis sem deploy.
     *
     * @return array<string, array<int, string>>
     */
    private function forbiddenGroups(): array
    {
        return [
            'promessa' => $this->expressions('ai.response.forbidden.promise'),
            'pedido_de_voto' => $this->expressions('ai.response.forbidden.vote_request'),
            'comparacao_com_adversarios' => $this->expressions('ai.response.forbidden.opponent_comparison'),
            'urgencia_artificial' => $this->expressions('ai.response.forbidden.urgency'),
            'intimidade_simulada' => $this->expressions('ai.response.forbidden.intimacy'),
            'alegacao_de_leitura_pessoal' => $this->expressions('ai.response.forbidden.personal_reading'),
            'coleta_de_dado_pessoal' => $this->expressions('ai.response.forbidden.personal_data'),
        ];
    }

    /**
     * @param  array<int, string>  $expressions
     */
    private function matches(string $normalized, array $expressions): bool
    {
        foreach ($expressions as $expression) {
            if ($normalized === $expression) {
                return true;
            }

            // Palavra ou frase inteira, nunca substring solta.
            if (preg_match('/(?:^|\s)'.preg_quote($expression, '/').'(?:\s|$)/', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function expressions(string $key): array
    {
        $raw = (string) $this->settings->get($key, '');

        return collect(explode('|', $raw))
            ->map(fn (string $item): string => $this->normalizer->normalize($item))
            ->filter(fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }
}
