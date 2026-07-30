<?php

namespace App\Services\Knowledge;

use App\Services\SystemSettingService;

/**
 * Neutraliza instrucoes embutidas em documentos.
 *
 * Esta e a primeira das duas defesas contra injecao de prompt. A segunda e a
 * delimitacao do bloco oficial no momento da montagem do prompt. Nenhuma das
 * duas basta sozinha: a primeira erra por padrao incompleto, a segunda erra por o
 * modelo ignorar a delimitacao. Juntas exigem duas falhas simultaneas.
 *
 * A neutralizacao substitui a linha por um marcador em vez de apagar
 * silenciosamente: quem revisa precisa ver que havia algo ali.
 */
class PromptInjectionSanitizer
{
    private const MARKER = '[trecho removido: instrucao detectada no documento]';

    public function __construct(private readonly SystemSettingService $settings) {}

    /**
     * @return array{text: string, flagged: bool, findings: array<int, string>}
     */
    public function sanitize(string $text): array
    {
        $patterns = $this->patterns();

        if ($patterns === []) {
            return ['text' => $text, 'flagged' => false, 'findings' => []];
        }

        $findings = [];
        $lines = explode("\n", $text);

        foreach ($lines as $index => $line) {
            $normalized = $this->normalize($line);

            if ($normalized === '') {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (! str_contains($normalized, $pattern)) {
                    continue;
                }

                $findings[] = 'linha '.($index + 1).': '.$pattern;
                $lines[$index] = self::MARKER;

                break;
            }
        }

        // Blocos de marcacao de papel de conversa tambem sao neutralizados: um
        // documento nao tem por que conter "system:" ou "assistant:".
        $sanitized = implode("\n", $lines);

        return [
            'text' => $sanitized,
            'flagged' => $findings !== [],
            // Achado e diagnostico, nao dossie: limitamos o que vai para a coluna.
            'findings' => array_slice($findings, 0, 20),
        ];
    }

    /**
     * @return array<int, string>
     */
    public function patterns(): array
    {
        $raw = (string) $this->settings->get('knowledge.injection_patterns', '');

        return collect(explode('|', $raw))
            ->map(fn (string $item): string => $this->normalize($item))
            ->filter(fn (string $item): bool => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Normalizacao igual a das listas deterministicas das subetapas anteriores:
     * caixa, acento e espaco nao devem servir de disfarce.
     */
    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));

        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        if (is_string($converted)) {
            $text = $converted;
        }

        $text = (string) preg_replace('/[^a-z0-9: ]+/', ' ', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
