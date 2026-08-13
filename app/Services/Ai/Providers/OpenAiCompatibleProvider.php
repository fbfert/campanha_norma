<?php

namespace App\Services\Ai\Providers;

use App\Contracts\AiProvider;
use App\Data\Ai\AiCompletionRequest;
use App\Data\Ai\AiCompletionResult;
use App\Exceptions\Ai\AiProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Provedor para APIs de chat no formato OpenAI.
 *
 * Serve OpenAI, Azure OpenAI, OpenRouter, Groq e servidores locais compatíveis.
 * A troca de fornecedor e feita apenas por configuração.
 */
class OpenAiCompatibleProvider implements AiProvider
{
    public function name(): string
    {
        return 'openai';
    }

    public function model(): string
    {
        return (string) config('ai.providers.openai.model');
    }

    public function complete(AiCompletionRequest $request): AiCompletionResult
    {
        $key = (string) config('ai.providers.openai.key');

        if ($key === '') {
            throw new AiProviderException(
                AiProviderException::NOT_CONFIGURED,
                'Provedor de IA sem credencial configurada.'
            );
        }

        $model = $request->model ?: $this->model();
        $startedAt = microtime(true);

        try {
            $response = $this->request($key)->post('/chat/completions', [
                'model' => $model,
                'temperature' => $request->temperature ?? (float) config('ai.temperature'),
                'max_tokens' => $request->maxOutputTokens ?? (int) config('ai.max_output_tokens'),
                'messages' => [
                    ['role' => 'system', 'content' => $request->systemPrompt],
                    ['role' => 'user', 'content' => $this->userContent($request)],
                ],
                // Saída estruturada exigida no próprio protocolo. A validação
                // local roda de qualquer forma, sem confiar no provedor.
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => $request->schemaName,
                        'strict' => true,
                        'schema' => $request->jsonSchema,
                    ],
                ],
            ]);
        } catch (ConnectionException $exception) {
            throw new AiProviderException(
                $this->isTimeout($exception) ? AiProviderException::TIMEOUT : AiProviderException::SERVICE_UNAVAILABLE,
                'Provedor de IA indisponível.',
                null,
                $exception::class,
            );
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->assertSuccessful($response);

        return $this->result($response, $model, $latencyMs);
    }

    /**
     * Conteúdo da mensagem do usuário: texto, ou texto mais imagem.
     *
     * Sem imagem continua sendo uma string simples, e não uma lista de uma
     * parte só. O formato antigo é aceito por servidores compatíveis que nunca
     * implementaram partes — trocá-lo por gosto quebraria instalação que
     * funciona hoje.
     *
     * @return string|array<int, array<string, mixed>>
     */
    private function userContent(AiCompletionRequest $request): string|array
    {
        if ($request->imageDataUri === null) {
            return $request->userPrompt;
        }

        return [
            ['type' => 'text', 'text' => $request->userPrompt],
            [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $request->imageDataUri,

                    // Resolução baixa de propósito. O que se quer saber é do
                    // que a foto trata e o que está escrito nela, não o
                    // detalhe do fundo — e alta resolução multiplica o custo
                    // por imagem sem mudar essa resposta.
                    'detail' => 'low',
                ],
            ],
        ];
    }

    private function request(string $key)
    {
        $request = Http::baseUrl((string) config('ai.providers.openai.url'))
            ->withToken($key)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('ai.timeout'))
            ->connectTimeout((int) config('ai.connect_timeout'));

        $organization = (string) config('ai.providers.openai.organization');

        return $organization === ''
            ? $request
            : $request->withHeaders(['OpenAI-Organization' => $organization]);
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();

        $code = match (true) {
            $status === 401 || $status === 403 => AiProviderException::UNAUTHORIZED,
            $status === 408 => AiProviderException::TIMEOUT,
            $status === 429 => AiProviderException::RATE_LIMITED,
            // Demais erros do cliente indicam pedido inválido para este modelo
            // ou schema. Repetir gastaria tokens sem chance de sucesso.
            $status >= 400 && $status < 500 => AiProviderException::BAD_REQUEST,
            default => AiProviderException::SERVICE_UNAVAILABLE,
        };

        throw new AiProviderException(
            $code,
            'Falha na comunicação com o provedor de IA.',
            $response->status(),
            $this->detail($response),
        );
    }

    private function result(Response $response, string $model, int $latencyMs): AiCompletionResult
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new AiProviderException(
                AiProviderException::INVALID_RESPONSE,
                'Resposta do provedor de IA não e um objeto valido.',
                $response->status(),
            );
        }

        $content = $payload['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new AiProviderException(
                AiProviderException::INVALID_RESPONSE,
                'Resposta do provedor de IA sem conteúdo.',
                $response->status(),
            );
        }

        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];

        return new AiCompletionResult(
            rawContent: $content,
            model: (string) ($payload['model'] ?? $model),
            promptTokens: isset($usage['prompt_tokens']) ? (int) $usage['prompt_tokens'] : null,
            completionTokens: isset($usage['completion_tokens']) ? (int) $usage['completion_tokens'] : null,
            totalTokens: isset($usage['total_tokens']) ? (int) $usage['total_tokens'] : null,
            latencyMs: $latencyMs,
        );
    }

    /**
     * Detalhe curto do erro do fornecedor, sem corpo de mensagem nem credencial.
     */
    private function detail(Response $response): ?string
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            return null;
        }

        $error = $payload['error'] ?? null;

        if (is_array($error)) {
            $detail = (string) ($error['code'] ?? $error['type'] ?? $error['message'] ?? '');

            return $detail === '' ? null : mb_substr($detail, 0, 255);
        }

        return null;
    }

    private function isTimeout(ConnectionException $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'timed out') || str_contains($message, 'timeout');
    }
}
