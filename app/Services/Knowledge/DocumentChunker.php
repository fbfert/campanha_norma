<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\PreparedChunk;
use App\Services\Knowledge\Extractors\ExtractedText;
use App\Services\SystemSettingService;

/**
 * Divide o texto extraido em trechos recuperáveis.
 *
 * O corte respeita fronteira de paragrafo quando cabe e fronteira de frase quando
 * o paragrafo e grande. Cortar no meio de uma frase produz trecho que cita mal:
 * a metade recuperada afirma algo que a outra metade qualificava.
 *
 * Tamanho e sobreposição vem de configuração.
 */
class DocumentChunker
{
    public function __construct(private readonly SystemSettingService $settings) {}

    /**
     * @return array<int, PreparedChunk>
     */
    public function chunk(ExtractedText $extracted): array
    {
        $size = max(200, (int) $this->settings->get('knowledge.chunk_size', 1200));
        $overlap = max(0, min((int) $this->settings->get('knowledge.chunk_overlap', 150), (int) floor($size / 2)));

        $chunks = [];
        $index = 0;

        if ($extracted->hasPages()) {
            foreach ($extracted->pages as $page => $pageText) {
                foreach ($this->split($pageText, $size, $overlap) as $piece) {
                    $chunks[] = new PreparedChunk(
                        index: $index++,
                        content: $piece,
                        page: (int) $page,
                        section: $this->sectionOf($piece),
                    );
                }
            }

            return $chunks;
        }

        foreach ($this->split($extracted->text, $size, $overlap) as $piece) {
            $chunks[] = new PreparedChunk(
                index: $index++,
                content: $piece,
                page: null,
                section: $this->sectionOf($piece),
            );
        }

        return $chunks;
    }

    /**
     * @return array<int, string>
     */
    private function split(string $text, int $size, int $overlap): array
    {
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", $text));

        if ($text === '') {
            return [];
        }

        if (mb_strlen($text) <= $size) {
            return [$text];
        }

        $units = $this->units($text, $size);
        $chunks = [];
        $current = '';

        foreach ($units as $unit) {
            $candidate = $current === '' ? $unit : $current."\n\n".$unit;

            if (mb_strlen($candidate) <= $size) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $chunks[] = $current;
                // A sobreposição mantem o fim do trecho anterior no início do
                // próximo, para que uma frase partida ainda apareça inteira em
                // algum trecho.
                $current = $overlap > 0 ? $this->tail($current, $overlap)."\n\n".$unit : $unit;

                if (mb_strlen($current) <= $size) {
                    continue;
                }
            }

            // Unidade sozinha maior que o limite: corta em pedacos duros.
            foreach ($this->hardSplit($unit, $size) as $piece) {
                $chunks[] = $piece;
            }

            $current = '';
        }

        if (trim($current) !== '') {
            $chunks[] = $current;
        }

        return array_values(array_filter(array_map('trim', $chunks), fn (string $c): bool => $c !== ''));
    }

    /**
     * Paragrafos, quebrados em frases quando o paragrafo não cabe.
     *
     * @return array<int, string>
     */
    private function units(string $text, int $size): array
    {
        $units = [];

        foreach (preg_split("/\n{2,}/", $text) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph) <= $size) {
                $units[] = $paragraph;

                continue;
            }

            $sentences = preg_split('/(?<=[.!?])\s+/u', $paragraph) ?: [$paragraph];

            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);

                if ($sentence !== '') {
                    $units[] = $sentence;
                }
            }
        }

        return $units;
    }

    /**
     * @return array<int, string>
     */
    private function hardSplit(string $text, int $size): array
    {
        $pieces = [];
        $length = mb_strlen($text);

        for ($offset = 0; $offset < $length; $offset += $size) {
            $piece = trim(mb_substr($text, $offset, $size));

            if ($piece !== '') {
                $pieces[] = $piece;
            }
        }

        return $pieces;
    }

    private function tail(string $text, int $overlap): string
    {
        return trim(mb_substr($text, -$overlap));
    }

    /**
     * Seção inferida de título Markdown ou de linha curta em caixa alta. Nulo
     * quando não ha sinal: metadado errado numa citação e pior que ausente.
     */
    private function sectionOf(string $chunk): ?string
    {
        foreach (explode("\n", $chunk) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^#{1,6}\s+(.{2,120})$/u', $line, $matches) === 1) {
                return trim($matches[1]);
            }

            if (mb_strlen($line) <= 80 && $line === mb_strtoupper($line) && preg_match('/\p{L}{3,}/u', $line) === 1) {
                return $line;
            }

            break;
        }

        return null;
    }
}
