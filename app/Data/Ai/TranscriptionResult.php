<?php

namespace App\Data\Ai;

class TranscriptionResult
{
    public function __construct(
        public readonly string $text,
        public readonly string $model,
        public readonly ?string $language = null,
        public readonly ?int $durationSeconds = null,
        public readonly ?int $latencyMs = null,
    ) {}

    /**
     * Áudio sem fala reconhecível.
     *
     * Vale distinguir de falha: o provedor respondeu, o arquivo estava integro,
     * e não havia o que transcrever. Tratar isso como resposta da pessoa
     * inventaria dado de pesquisa.
     */
    public function isEmpty(): bool
    {
        return trim($this->text) === '';
    }

    public function estimatedCost(): ?float
    {
        $porMinuto = config('ai.cost.transcription_per_minute');

        if ($porMinuto === null || $this->durationSeconds === null) {
            return null;
        }

        return round(($this->durationSeconds / 60) * (float) $porMinuto, 6);
    }
}
