<?php

namespace App\Services\Knowledge\Embeddings;

use App\Contracts\EmbeddingProvider;
use App\Exceptions\Knowledge\KnowledgeProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Provedor de embeddings para APIs no formato OpenAI.
 *
 * Serve OpenAI, Azure OpenAI, servidores locais compatíveis e qualquer endpoint
 * que exponha `/embeddings` com o mesmo contrato. A troca e feita apenas por
 * configuração.
 */
class OpenAiCompatibleEmbeddingProvider implements EmbeddingProvider
{
    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return (string) config('knowledge.embeddings.openai.model');
    }

    public function dimensions(): int
    {
        return (int) config('knowledge.embeddings.openai.dimensions');
    }

    public function isConfigured(): bool
    {
        return (string) config('knowledge.embeddings.openai.key') !== '';
    }

    /**
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>>
     */
    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        if (! $this->isConfigured()) {
            throw KnowledgeProviderException::code(KnowledgeProviderException::EMBEDDINGS_NOT_CONFIGURED);
        }

        $vectors = [];

        // Lotes limitados: um documento longo não vira uma requisição única de
        // tamanho imprevisível.
        foreach (array_chunk($texts, max(1, (int) config('knowledge.embedding_batch_size'))) as $batch) {
            foreach ($this->requestBatch(array_values($batch)) as $vector) {
                $vectors[] = $vector;
            }
        }

        if (count($vectors) !== count($texts)) {
            throw KnowledgeProviderException::code(
                KnowledgeProviderException::INVALID_RESPONSE,
                'Quantidade de vetores diferente da quantidade de trechos.',
            );
        }

        return $vectors;
    }

    /**
     * @param  array<int, string>  $batch
     * @return array<int, array<int, float>>
     */
    private function requestBatch(array $batch): array
    {
        try {
            $response = Http::baseUrl((string) config('knowledge.embeddings.openai.url'))
                ->withToken((string) config('knowledge.embeddings.openai.key'))
                ->acceptJson()
                ->asJson()
                ->timeout((int) config('knowledge.timeout'))
                ->connectTimeout((int) config('knowledge.connect_timeout'))
                ->post('/embeddings', [
                    'model' => $this->model(),
                    'input' => $batch,
                ]);
        } catch (ConnectionException $exception) {
            throw KnowledgeProviderException::code(
                $this->isTimeout($exception)
                    ? KnowledgeProviderException::TIMEOUT
                    : KnowledgeProviderException::SERVICE_UNAVAILABLE,
                $exception::class,
            );
        }

        $this->assertSuccessful($response);

        return $this->vectors($response, count($batch));
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        $code = match (true) {
            $status === 401 || $status === 403 => KnowledgeProviderException::UNAUTHORIZED,
            $status === 408 => KnowledgeProviderException::TIMEOUT,
            $status === 429 => KnowledgeProviderException::RATE_LIMITED,
            // Erro de cliente que não e limite nem timeout indica pedido inválido
            // para este modelo. Repetir gastaria chamadas sem chance de sucesso.
            $status >= 400 && $status < 500 => KnowledgeProviderException::BAD_REQUEST,
            default => KnowledgeProviderException::SERVICE_UNAVAILABLE,
        };

        throw KnowledgeProviderException::code($code, $this->detail($response));
    }

    /**
     * @return array<int, array<int, float>>
     */
    private function vectors(Response $response, int $expected): array
    {
        $payload = $response->json();
        $data = is_array($payload) && is_array($payload['data'] ?? null) ? $payload['data'] : null;

        if ($data === null || count($data) !== $expected) {
            throw KnowledgeProviderException::code(KnowledgeProviderException::INVALID_RESPONSE);
        }

        // A ordem devolvida não e garantida pelo contrato: reordenamos pelo
        // índice declarado em cada item.
        $ordered = [];

        foreach ($data as $position => $item) {
            $index = isset($item['index']) ? (int) $item['index'] : (int) $position;
            $embedding = $item['embedding'] ?? null;

            if (! is_array($embedding) || $embedding === []) {
                throw KnowledgeProviderException::code(KnowledgeProviderException::INVALID_RESPONSE);
            }

            $vector = array_map(static fn ($value): float => (float) $value, array_values($embedding));

            if (count($vector) !== $this->dimensions()) {
                throw KnowledgeProviderException::code(
                    KnowledgeProviderException::DIMENSION_MISMATCH,
                    'Esperado '.$this->dimensions().', recebido '.count($vector).'.',
                );
            }

            $ordered[$index] = $vector;
        }

        ksort($ordered);

        return array_values($ordered);
    }

    private function detail(Response $response): ?string
    {
        $payload = $response->json();
        $error = is_array($payload) ? ($payload['error'] ?? null) : null;

        if (! is_array($error)) {
            return null;
        }

        $detail = (string) ($error['code'] ?? $error['type'] ?? $error['message'] ?? '');

        return $detail === '' ? null : mb_substr($detail, 0, 255);
    }

    private function isTimeout(ConnectionException $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'timed out') || str_contains($message, 'timeout');
    }
}
