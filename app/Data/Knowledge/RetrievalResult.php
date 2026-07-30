<?php

namespace App\Data\Knowledge;

use App\Enums\RetrievalStrategy;

readonly class RetrievalResult
{
    /**
     * @param  array<int, RetrievedChunk>  $chunks
     */
    public function __construct(
        public array $chunks,
        public RetrievalStrategy $strategy,
        public int $candidateCount = 0,
        public int $durationMs = 0,
        public ?string $degradedReason = null,
    ) {}

    public static function empty(RetrievalStrategy $strategy, ?string $reason = null): self
    {
        return new self([], $strategy, 0, 0, $reason);
    }

    public function isEmpty(): bool
    {
        return $this->chunks === [];
    }

    public function count(): int
    {
        return count($this->chunks);
    }

    public function maxScore(): ?float
    {
        return $this->isEmpty() ? null : max(array_map(fn (RetrievedChunk $c): float => $c->score, $this->chunks));
    }

    public function minScore(): ?float
    {
        return $this->isEmpty() ? null : min(array_map(fn (RetrievedChunk $c): float => $c->score, $this->chunks));
    }

    /**
     * Referências validas para citação. Uma citação fora deste conjunto e, por
     * definição, invenção.
     *
     * @return array<int, string>
     */
    public function allowedReferences(): array
    {
        return array_map(fn (RetrievedChunk $c): string => $c->reference(), $this->chunks);
    }

    /** @return array<int, int> */
    public function allowedDocumentIds(): array
    {
        return array_values(array_unique(array_map(fn (RetrievedChunk $c): int => $c->documentId, $this->chunks)));
    }

    public function findByReference(string $reference): ?RetrievedChunk
    {
        foreach ($this->chunks as $chunk) {
            if ($chunk->reference() === $reference) {
                return $chunk;
            }
        }

        return null;
    }

    /** @return array<int, RetrievedChunk> */
    public function forDocument(int $documentId): array
    {
        return array_values(array_filter(
            $this->chunks,
            fn (RetrievedChunk $c): bool => $c->documentId === $documentId,
        ));
    }
}
