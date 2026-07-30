<?php

namespace App\Data\Knowledge;

use App\Enums\RetrievalStrategy;

/**
 * Consulta de recuperação.
 *
 * Carrega apenas texto e parâmetros. Não carrega identificador de contato: a
 * recuperação não precisa saber quem perguntou, e não poder saber e a forma mais
 * simples de garantir que não haverá microdirecionamento individual.
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
