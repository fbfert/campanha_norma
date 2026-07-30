<?php

namespace App\Data\Knowledge;

/**
 * Trecho recuperado, com procedência completa.
 *
 * O conteúdo viaja junto porque ele e o que será gravado como snapshot no log de
 * recuperação: a rastreabilidade de uma resposta antiga não pode depender de o
 * trecho continuar existindo.
 */
readonly class RetrievedChunk
{
    public function __construct(
        public int $chunkId,
        public int $documentId,
        public int $baseId,
        public string $documentTitle,
        public int $documentVersion,
        public string $content,
        public float $score,
        public ?int $page = null,
        public ?string $section = null,
        public ?string $externalChunkId = null,
    ) {}

    /**
     * Referência estável usada nas citações. Prefere o identificador externo
     * quando existe, para que a troca de provedor não invalide citação antiga.
     */
    public function reference(): string
    {
        return $this->externalChunkId ?? (string) $this->chunkId;
    }

    /** @return array<string, mixed> */
    public function toLogArray(): array
    {
        return [
            'chunk_id' => $this->chunkId,
            'document_id' => $this->documentId,
            'document_version' => $this->documentVersion,
            'page' => $this->page,
            'section' => $this->section,
            'score' => round($this->score, 6),
        ];
    }
}
