<?php

namespace App\Data\Ai;

/**
 * Resultado bruto do provedor, antes da validação de schema local.
 */
readonly class AiCompletionResult
{
    public function __construct(
        public string $rawContent,
        public string $model,
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
        public ?int $totalTokens = null,
        public int $latencyMs = 0,
    ) {}

    /**
     * Custo estimado opcional. Retorna null quando a configuração de preço não
     * existe, e nenhuma funcionalidade depende deste valor.
     */
    public function estimatedCost(): ?float
    {
        $input = config('ai.cost.input_per_1k');
        $output = config('ai.cost.output_per_1k');

        if ($input === null && $output === null) {
            return null;
        }

        $cost = 0.0;
        $cost += (($this->promptTokens ?? 0) / 1000) * (float) $input;
        $cost += (($this->completionTokens ?? 0) / 1000) * (float) $output;

        return round($cost, 6);
    }
}
