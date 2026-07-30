<?php

namespace App\Data\Knowledge;

/**
 * Trecho ja extraido, sanitizado e pronto para indexacao.
 *
 * O embedding e opcional: a estrategia lexica nao precisa dele, e a vetorial o
 * recebe preenchido pelo servico de indexacao.
 */
readonly class PreparedChunk
{
    /**
     * @param  array<int, float>|null  $embedding
     */
    public function __construct(
        public int $index,
        public string $content,
        public ?int $page = null,
        public ?string $section = null,
        public ?array $embedding = null,
        public ?string $externalChunkId = null,
    ) {}

    public function hash(): string
    {
        return hash('sha256', $this->content);
    }

    public function tokenEstimate(): int
    {
        // Estimativa deliberadamente grosseira: serve para limitar contexto, nao
        // para cobranca. Portugues fica proximo de quatro caracteres por token.
        return (int) ceil(mb_strlen($this->content) / 4);
    }

    /**
     * @param  array<int, float>  $embedding
     */
    public function withEmbedding(array $embedding): self
    {
        return new self($this->index, $this->content, $this->page, $this->section, $embedding, $this->externalChunkId);
    }

    public function withExternalId(?string $externalChunkId): self
    {
        return new self($this->index, $this->content, $this->page, $this->section, $this->embedding, $externalChunkId);
    }
}
