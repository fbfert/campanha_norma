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
    /**
     * @param  ?string  $imageDataUri  Imagem a ser lida junto do texto, como
     *                                 `data:image/jpeg;base64,...`. Só o
     *                                 provedor sabe como acomodá-la no
     *                                 protocolo dele; aqui ela é só um dado.
     */
    public function __construct(
        public string $systemPrompt,
        public string $userPrompt,
        public string $schemaName,
        public array $jsonSchema,
        public ?string $model = null,
        public ?int $maxOutputTokens = null,
        public ?float $temperature = null,
        public ?string $imageDataUri = null,
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
            // A imagem entra pelo resumo, e não inteira: o hash existe para
            // correlacionar chamadas, e concatenar seis megabytes de base64
            // para depois reduzi-los a 64 caracteres é trabalho jogado fora.
            $this->imageDataUri === null ? '' : hash('sha256', $this->imageDataUri),
        ]));
    }
}
