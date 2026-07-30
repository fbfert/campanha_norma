<?php

namespace App\Services\Knowledge;

use App\Services\SystemSettingService;

/**
 * Normalizacao usada na indexacao e na busca.
 *
 * A mesma funcao roda nos dois lados de proposito: se o texto do documento e a
 * consulta forem normalizados por regras diferentes, a busca lexica erra de um
 * jeito que nao aparece em teste unitario nenhum dos dois lados isolado.
 */
class TextNormalizer
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function normalize(?string $text): string
    {
        $text = mb_strtolower(trim((string) $text));

        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        if (is_string($converted)) {
            $text = $converted;
        }

        // Digitos permanecem: numero e data importam para a validacao de
        // fundamentacao.
        $text = (string) preg_replace('/[^a-z0-9]+/', ' ', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Termos uteis de uma consulta, sem palavras vazias e sem termo curto.
     *
     * @return array<int, string>
     */
    public function terms(?string $text): array
    {
        $stopWords = $this->stopWords();
        $minLength = max(2, (int) $this->settings->get('knowledge.min_term_length', 3));

        return collect(explode(' ', $this->normalize($text)))
            ->filter(fn (string $term): bool => $term !== '' && mb_strlen($term) >= $minLength)
            ->reject(fn (string $term): bool => in_array($term, $stopWords, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function stopWords(): array
    {
        $raw = (string) $this->settings->get('knowledge.stop_words', '');

        return collect(explode('|', $raw))
            ->map(fn (string $item): string => $this->normalize($item))
            ->filter(fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }
}
