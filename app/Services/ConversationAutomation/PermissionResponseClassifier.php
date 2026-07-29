<?php

namespace App\Services\ConversationAutomation;

use App\Enums\PermissionResponseClassification;
use App\Services\SystemSettingService;

/**
 * Classificador deterministico de respostas curtas de permissao.
 *
 * Nao usa IA, embeddings, similaridade ou ranking. A decisao deriva apenas do
 * texto normalizado e das listas de expressoes configuraveis em system_settings.
 */
class PermissionResponseClassifier
{
    public function __construct(private readonly SystemSettingService $settings) {}

    /**
     * @return array{classification: PermissionResponseClassification, normalized: string, matched: ?string, reason: string}
     */
    public function classify(?string $text): array
    {
        $normalized = $this->normalize((string) $text);

        if ($normalized === '') {
            return $this->result(PermissionResponseClassification::Ambiguous, $normalized, null, 'texto_vazio');
        }

        // Opt-out tem prioridade absoluta sobre qualquer outra classificacao.
        $optOut = $this->firstMatch($normalized, $this->expressions('conversation_automation.opt_out_expressions'));
        if ($optOut !== null) {
            return $this->result(PermissionResponseClassification::OptOut, $normalized, $optOut, 'expressao_opt_out');
        }

        $yesExpressions = $this->expressions('conversation_automation.yes_expressions');
        $noExpressions = $this->expressions('conversation_automation.no_expressions');

        // Correspondencia exata vale mesmo em textos longos.
        // Precedencia: opt_out > permission_no > permission_yes > ambiguous.
        // A negativa e avaliada antes da positiva tambem na correspondencia
        // exata, para que uma sobreposicao entre as listas nunca resulte em
        // consentimento presumido.
        if (in_array($normalized, $noExpressions, true)) {
            return $this->result(PermissionResponseClassification::PermissionNo, $normalized, $normalized, 'expressao_exata_negativa');
        }

        if (in_array($normalized, $yesExpressions, true)) {
            return $this->result(PermissionResponseClassification::PermissionYes, $normalized, $normalized, 'expressao_exata_positiva');
        }

        // Acima do limite de palavras nao classificamos por aproximacao.
        $maxWords = (int) $this->settings->get('conversation_automation.short_answer_max_words', 6);
        if ($this->wordCount($normalized) > $maxWords) {
            return $this->result(PermissionResponseClassification::Ambiguous, $normalized, null, 'texto_longo');
        }

        $no = $this->firstMatch($normalized, $noExpressions);
        $yes = $this->firstMatch($normalized, $yesExpressions);

        // Texto curto contendo positiva e negativa e ambiguo, nao positivo.
        if ($no !== null && $yes !== null) {
            return $this->result(PermissionResponseClassification::Ambiguous, $normalized, null, 'positiva_e_negativa');
        }

        if ($no !== null) {
            return $this->result(PermissionResponseClassification::PermissionNo, $normalized, $no, 'expressao_negativa');
        }

        if ($yes !== null) {
            return $this->result(PermissionResponseClassification::PermissionYes, $normalized, $yes, 'expressao_positiva');
        }

        return $this->result(PermissionResponseClassification::Ambiguous, $normalized, null, 'sem_correspondencia');
    }

    /**
     * Normaliza caixa, espacos, pontuacao e acentos sem destruir o texto original,
     * que permanece disponivel para registro no chamador.
     */
    public function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = strtr($text, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);

        // Emojis e pontuacao viram separadores para nao colar palavras.
        $text = preg_replace('/[^a-z0-9]+/u', ' ', $text) ?? '';

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    /**
     * Correspondencia por palavra inteira ou frase inteira, nunca por substring solta,
     * para que "nao" nunca case dentro de outra palavra.
     *
     * @param  array<int, string>  $expressions
     */
    private function firstMatch(string $normalized, array $expressions): ?string
    {
        foreach ($expressions as $expression) {
            if ($expression === '') {
                continue;
            }

            if ($normalized === $expression) {
                return $expression;
            }

            if (preg_match('/(?:^|\s)'.preg_quote($expression, '/').'(?:\s|$)/', $normalized) === 1) {
                return $expression;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function expressions(string $key): array
    {
        $raw = (string) $this->settings->get($key, '');

        return collect(explode('|', $raw))
            ->map(fn (string $item): string => $this->normalize($item))
            ->filter(fn (string $item): bool => $item !== '')
            ->unique()
            // Expressoes mais longas primeiro para "nao quero" vencer "nao".
            ->sortByDesc(fn (string $item): int => mb_strlen($item))
            ->values()
            ->all();
    }

    private function wordCount(string $normalized): int
    {
        return $normalized === '' ? 0 : count(explode(' ', $normalized));
    }

    /**
     * @return array{classification: PermissionResponseClassification, normalized: string, matched: ?string, reason: string}
     */
    private function result(PermissionResponseClassification $classification, string $normalized, ?string $matched, string $reason): array
    {
        return [
            'classification' => $classification,
            'normalized' => $normalized,
            'matched' => $matched,
            'reason' => $reason,
        ];
    }
}
