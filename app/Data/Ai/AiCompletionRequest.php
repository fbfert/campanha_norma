<?php

namespace App\Data\Ai;

/**
 * Requisição de completude estruturada, independente de fornecedor.
 *
 * Não carrega chave, cabeçalho ou qualquer credencial: essas vivem apenas na
 * configuração do provedor.
 */
readonly class AiCompletionRequest
{
    /**
     * @param  array<string, mixed>  $jsonSchema
     */
    public function __construct(
        public string $systemPrompt,
        public string $userPrompt,
        public string $schemaName,
        public array $jsonSchema,
        public ?string $model = null,
        public ?int $maxOutputTokens = null,
        public ?float $temperature = null,
    ) {}

    /**
     * Hash estável da requisição, usado para deduplicação e correlação.
     * Não inclui credencial nem identificador de conversa.
     */
    public function hash(string $purpose, string $promptVersion, int $schemaVersion, string $model): string
    {
        return hash('sha256', implode('|', [
            $purpose,
            $promptVersion,
            (string) $schemaVersion,
            $model,
            $this->schemaName,
            $this->systemPrompt,
            $this->userPrompt,
        ]));
    }
}
