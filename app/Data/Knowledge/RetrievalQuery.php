<?php

namespace App\Data\Knowledge;

use App\Enums\RetrievalStrategy;

/**
 * Consulta de recuperacao.
 *
 * Carrega apenas texto e parametros. Nao carrega identificador de contato: a
 * recuperacao nao precisa saber quem perguntou, e nao poder saber e a forma mais
 * simples de garantir que nao havera microdirecionamento individual.
 */
readonly class RetrievalQuery
{
    /**
     * @param  array<int, int>  $baseIds
     */
    public function __construct(
        public string $text,
        public array $baseIds,
        public RetrievalStrategy $strategy,
        public int $topK,
        public float $threshold,
        public int $maxContextChars,
    ) {}

    public function hasBases(): bool
    {
        return $this->baseIds !== [];
    }
}
