<?php

namespace App\Contracts;

use App\Data\Knowledge\GroundingVerdict;
use App\Data\Knowledge\RetrievalResult;

interface AnswerGroundingValidator
{
    /**
     * Confere, depois do modelo, se cada afirmação factual do texto tem suporte
     * nos trechos efetivamente recuperados.
     *
     * @param  array<int, array<string, mixed>>  $citations  citações declaradas pelo modelo
     * @param  bool  $claimedGrounded  valor devolvido pelo modelo: sinal, nunca autorização
     */
    public function validate(
        ?string $text,
        array $citations,
        RetrievalResult $retrieval,
        bool $claimedGrounded = false,
    ): GroundingVerdict;
}
