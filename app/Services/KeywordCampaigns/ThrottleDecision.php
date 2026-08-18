<?php

namespace App\Services\KeywordCampaigns;

/**
 * O que o limitador respondeu.
 *
 * Recusa aqui nunca significa descarte: significa "não agora", e carrega em
 * quantos segundos tentar de novo. Ninguém perde a confirmação por ter escrito
 * no minuto errado.
 */
readonly class ThrottleDecision
{
    private function __construct(
        public bool $permitida,
        public int $tentarEmSegundos = 0,
        public ?string $motivo = null,
    ) {}

    public static function permitida(): self
    {
        return new self(true);
    }

    public static function adiada(int $segundos, string $motivo): self
    {
        return new self(false, max(1, $segundos), $motivo);
    }
}
