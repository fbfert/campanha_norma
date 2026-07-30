<?php

namespace App\Services\Knowledge;

use App\Contracts\AnswerGroundingValidator;
use App\Data\Knowledge\GroundingVerdict;
use App\Data\Knowledge\RetrievalResult;
use App\Data\Knowledge\RetrievedChunk;
use App\Enums\GroundingStatus;
use App\Models\KnowledgeDocument;
use App\Services\SystemSettingService;

/**
 * Validação de fundamentação, determinística e posterior ao modelo.
 *
 * O campo `grounded` que o modelo devolve e sinal, nunca autorização. Esta classe
 * existe exatamente para o caso em que ele afirma estar fundamentado e não esta.
 *
 * Nenhuma reprovação produz texto alternativo: produz recusa e handoff.
 */
class GroundingValidator implements AnswerGroundingValidator
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly TextNormalizer $normalizer,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $citations
     */
    public function validate(
        ?string $text,
        array $citations,
        RetrievalResult $retrieval,
        bool $claimedGrounded = false,
    ): GroundingVerdict {
        $text = trim((string) $text);

        if ($text === '') {
            // Ação sem texto para o contato não afirma nada sobre o mundo.
            return new GroundingVerdict(GroundingStatus::NotRequired);
        }

        $resolved = $this->resolveCitations($citations, $retrieval);
        $factual = $this->isFactual($text);

        if ($resolved['invalid'] !== []) {
            return new GroundingVerdict(
                $resolved['invalidStatus'],
                $resolved['valid'],
                $resolved['invalid'],
                $factual,
            );
        }

        if ($claimedGrounded && $resolved['valid'] === []) {
            return new GroundingVerdict(
                GroundingStatus::GroundedWithoutCitation,
                [],
                ['modelo declarou fundamentado sem citar nada'],
                $factual,
            );
        }

        if (! $factual) {
            return new GroundingVerdict(GroundingStatus::NotRequired, $resolved['valid'], [], false);
        }

        if ($resolved['valid'] === []) {
            return new GroundingVerdict(
                GroundingStatus::NoEvidence,
                [],
                ['afirmação factual sem nenhuma citação valida'],
                true,
            );
        }

        $support = $this->citedContent($resolved['valid']);

        // Data antes de número: uma data e feita de números, e conferir número
        // primeiro faria toda data sem suporte ser reportada como número sem
        // suporte. A recusa seria a mesma, o motivo registrado seria enganoso.
        if (($missing = $this->unsupportedDates($text, $support)) !== []) {
            return new GroundingVerdict(
                GroundingStatus::UnsupportedDate,
                $resolved['valid'],
                ['datas sem suporte: '.implode(', ', array_slice($missing, 0, 5))],
                true,
            );
        }

        if (($missing = $this->unsupportedNumbers($text, $support)) !== []) {
            return new GroundingVerdict(
                GroundingStatus::UnsupportedNumber,
                $resolved['valid'],
                ['números sem suporte: '.implode(', ', array_slice($missing, 0, 5))],
                true,
            );
        }

        if ($this->hasUnsupportedCommitment($text, $support)) {
            return new GroundingVerdict(
                GroundingStatus::UnsupportedCommitment,
                $resolved['valid'],
                ['compromisso sem suporte explícito nos trechos citados'],
                true,
            );
        }

        return new GroundingVerdict(GroundingStatus::Grounded, $resolved['valid'], [], true);
    }

    /**
     * Casa cada citação declarada com o conjunto efetivamente recuperado.
     *
     * Citação invalida também e devolvida com motivo: saber que o modelo citou
     * algo inexistente e informação de auditoria, não ruído.
     *
     * @param  array<int, array<string, mixed>>  $citations
     * @return array{valid: array<int, array<string, mixed>>, invalid: array<int, string>, invalidStatus: GroundingStatus}
     */
    private function resolveCitations(array $citations, RetrievalResult $retrieval): array
    {
        $valid = [];
        $invalid = [];
        $invalidStatus = GroundingStatus::InvalidCitation;

        foreach ($citations as $citation) {
            if (! is_array($citation)) {
                $invalid[] = 'citação malformada';

                continue;
            }

            $reference = isset($citation['chunk_id']) ? trim((string) $citation['chunk_id']) : '';
            $documentId = isset($citation['document_id']) ? (int) $citation['document_id'] : 0;

            $chunk = $reference === '' ? null : $retrieval->findByReference($reference);

            // Sem referência de trecho utilizável, aceitamos o documento desde que
            // ele esteja no conjunto recuperado: o modelo acertou a fonte e errou o
            // identificador interno, que não e informação que ele deva inventar.
            if ($chunk === null && $documentId > 0) {
                $chunk = $retrieval->forDocument($documentId)[0] ?? null;
            }

            if ($chunk === null) {
                $invalid[] = 'citação fora do conjunto recuperado: '.($reference !== '' ? $reference : 'documento '.$documentId);

                continue;
            }

            if ($documentId > 0 && $documentId !== $chunk->documentId) {
                $invalid[] = 'documento citado não corresponde ao trecho: '.$documentId;

                continue;
            }

            if (! $this->documentIsRetrievable($chunk->documentId)) {
                $invalidStatus = GroundingStatus::ObsoleteCitation;
                $invalid[] = 'documento não recuperável: '.$chunk->documentId;

                continue;
            }

            $valid[] = [
                'chunk' => $chunk,
                'document_id' => $chunk->documentId,
                'chunk_reference' => $chunk->reference(),
                'document_title' => $chunk->documentTitle,
                'document_version' => $chunk->documentVersion,
                // Página e seção vem do trecho recuperado, não do que o modelo
                // disse: metadado de citação não e coisa que ele deva escolher.
                'page' => $chunk->page,
                'section' => $chunk->section,
                'score' => $chunk->score,
                'content' => $chunk->content,
            ];
        }

        return [
            'valid' => $this->dedupe($valid),
            'invalid' => $invalid,
            'invalidStatus' => $invalidStatus,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $citations
     * @return array<int, array<string, mixed>>
     */
    private function dedupe(array $citations): array
    {
        $seen = [];
        $result = [];

        foreach ($citations as $citation) {
            $key = (string) $citation['chunk_reference'];

            if (in_array($key, $seen, true)) {
                continue;
            }

            $seen[] = $key;
            $result[] = $citation;
        }

        return $result;
    }

    private function documentIsRetrievable(int $documentId): bool
    {
        return KnowledgeDocument::query()->whereKey($documentId)->retrievable()->exists();
    }

    /**
     * Afirmação factual: número relevante, data, valor, ou expressão configurada
     * que caracterize declaração sobre a pessoa representada.
     */
    public function isFactual(string $text): bool
    {
        if ($this->relevantNumbers($text) !== [] || $this->dateTokens($text) !== []) {
            return true;
        }

        $normalized = $this->normalizer->normalize($text);

        foreach ($this->expressions('knowledge.factual_markers') as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Números que caracterizam afirmação factual.
     *
     * Digito único e ignorado de propósito: "uma pergunta" e "1 pergunta" não são
     * afirmação sobre o mundo, e exigir evidência para eles faria todo texto cair
     * em handoff.
     *
     * @return array<int, string>
     */
    private function relevantNumbers(string $text): array
    {
        preg_match_all('/(?:r\$\s*)?\d[\d.,]*\s*%?/iu', $text, $matches);

        $tokens = [];

        foreach ($matches[0] ?? [] as $token) {
            $token = trim($token);
            $digits = preg_replace('/\D/', '', $token) ?? '';

            if (mb_strlen($digits) >= 2 || str_contains($token, '%') || stripos($token, 'r$') === 0) {
                $tokens[] = $token;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @return array<int, string>
     */
    private function dateTokens(string $text): array
    {
        $tokens = [];

        // Data numérica e ano de quatro digitos.
        preg_match_all('/\b\d{1,2}\/\d{1,2}(?:\/\d{2,4})?\b|\b(?:19|20)\d{2}\b/u', $text, $matches);
        foreach ($matches[0] ?? [] as $token) {
            $tokens[] = trim($token);
        }

        // Data escrita por extenso.
        preg_match_all(
            '/\b\d{1,2}\s+de\s+(janeiro|fevereiro|marco|março|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)\b/iu',
            $text,
            $matches
        );
        foreach ($matches[0] ?? [] as $token) {
            $tokens[] = trim($token);
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  array<int, array<string, mixed>>  $citations
     * @return array{raw: string, normalized: string, digits: string}
     */
    private function citedContent(array $citations): array
    {
        $raw = implode("\n", array_map(
            static fn (array $citation): string => (string) $citation['content'],
            $citations,
        ));

        return [
            'raw' => mb_strtolower($raw),
            'normalized' => $this->normalizer->normalize($raw),
            'digits' => (string) preg_replace('/\D/', '', $raw),
        ];
    }

    /**
     * @param  array{raw: string, normalized: string, digits: string}  $support
     * @return array<int, string>
     */
    private function unsupportedNumbers(string $text, array $support): array
    {
        $missing = [];

        // Datas já foram conferidas e não devem ser reavaliadas em pedacos: o ano
        // de uma data com suporte não e um número solto sem suporte.
        foreach ($this->relevantNumbers($this->withoutDates($text)) as $token) {
            if ($this->isSupported($token, $support)) {
                continue;
            }

            $missing[] = $token;
        }

        return $missing;
    }

    /**
     * Texto sem os trechos já reconhecidos como data.
     */
    private function withoutDates(string $text): string
    {
        foreach ($this->dateTokens($text) as $token) {
            $text = str_replace($token, ' ', $text);
        }

        return $text;
    }

    /**
     * @param  array{raw: string, normalized: string, digits: string}  $support
     * @return array<int, string>
     */
    private function unsupportedDates(string $text, array $support): array
    {
        $missing = [];

        foreach ($this->dateTokens($text) as $token) {
            if ($this->isSupported($token, $support)) {
                continue;
            }

            $missing[] = $token;
        }

        return $missing;
    }

    /**
     * Suporte por ocorrência literal ou por sequência de digitos.
     *
     * A comparação por digitos existe porque "1.500", "1500" e "1 500" são o mesmo
     * número escrito de três formas, e reprovar por formatação produziria handoff
     * onde havia evidência.
     *
     * @param  array{raw: string, normalized: string, digits: string}  $support
     */
    private function isSupported(string $token, array $support): bool
    {
        $needle = mb_strtolower(trim($token));

        if ($needle !== '' && str_contains($support['raw'], $needle)) {
            return true;
        }

        $normalized = $this->normalizer->normalize($token);

        if ($normalized !== '' && str_contains($support['normalized'], $normalized)) {
            return true;
        }

        $digits = (string) preg_replace('/\D/', '', $token);

        return mb_strlen($digits) >= 2 && str_contains($support['digits'], $digits);
    }

    /**
     * @param  array{raw: string, normalized: string, digits: string}  $support
     */
    private function hasUnsupportedCommitment(string $text, array $support): bool
    {
        $normalized = $this->normalizer->normalize($text);
        $found = false;

        foreach ($this->expressions('knowledge.commitment_markers') as $marker) {
            if (str_contains($normalized, $marker)) {
                $found = true;

                break;
            }
        }

        if (! $found) {
            return false;
        }

        // Compromisso so passa quando o trecho citado também fala em termos de
        // proposta, projeto ou programa formalizado.
        foreach ($this->expressions('knowledge.commitment_support_markers') as $marker) {
            if (str_contains($support['normalized'], $marker)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
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

    /**
     * Trechos usados, prontos para persistir como citação.
     *
     * @param  array<int, array<string, mixed>>  $citations
     * @return array<int, RetrievedChunk>
     */
    public static function chunksOf(array $citations): array
    {
        return array_values(array_filter(array_map(
            static fn (array $citation) => $citation['chunk'] ?? null,
            $citations,
        ), static fn ($chunk): bool => $chunk instanceof RetrievedChunk));
    }
}
