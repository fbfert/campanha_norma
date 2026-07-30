<?php

namespace App\Services\Ai;

use App\Data\Ai\AiCompletionRequest;
use App\Enums\AiRunPurpose;
use App\Enums\AiRunStatus;
use App\Exceptions\Ai\AiProviderException;
use App\Models\AiRun;
use App\Services\SystemSettingService;
use Illuminate\Support\Facades\Log;

/**
 * Executa uma chamada de IA com disjuntor, tentativas, backoff, validação de
 * schema e registro auditável de cada tentativa.
 *
 * Cada tentativa vira uma linha em `ai_runs`. O log e append-only de propósito:
 * uma nova tentativa nunca apaga o rastro da anterior.
 */
class AiClient
{
    public function __construct(
        private readonly AiProviderManager $providers,
        private readonly AiCircuitBreaker $circuit,
        private readonly AiResponseValidator $validator,
        private readonly SystemSettingService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $schema
     * @param  array{conversation_id?: ?int, source_message_id?: ?int, conversation_flow_id?: ?int}  $context
     */
    public function execute(
        AiRunPurpose $purpose,
        AiCompletionRequest $request,
        array $schema,
        string $promptVersion,
        int $schemaVersion,
        array $context = [],
    ): AiRun {
        $provider = $this->providers->provider();
        $model = $provider->model();
        $hash = $request->hash($purpose->value, $promptVersion, $schemaVersion, $model);
        $maxAttempts = max(1, (int) $this->settings->get('ai.max_attempts', 3));
        $backoffMs = max(0, (int) $this->settings->get('ai.retry_backoff_ms', 500));

        $run = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $run = $this->startRun($purpose, $provider->name(), $model, $promptVersion, $schemaVersion, $hash, $attempt, $context);

            try {
                $this->circuit->assertClosed($provider->name());

                $result = $provider->complete($request);

                $validation = $this->validator->validate($result->rawContent, $schema);

                if (! $validation['valid']) {
                    // Saída invalida e problema de conteúdo, não de disponibilidade:
                    // não conta para o disjuntor e não vale nova tentativa.
                    $this->circuit->recordSuccess($provider->name());

                    $this->finishRun($run, AiRunStatus::InvalidOutput, [
                        'error_code' => AiProviderException::INVALID_RESPONSE,
                        'error_message' => 'Saída do modelo fora do schema: '.implode(', ', array_slice($validation['errors'], 0, 5)),
                        'latency_ms' => $result->latencyMs,
                        'prompt_tokens' => $result->promptTokens,
                        'completion_tokens' => $result->completionTokens,
                        'total_tokens' => $result->totalTokens,
                        'estimated_cost' => $result->estimatedCost(),
                    ]);

                    $this->logOutcome($run, 'ai.invalid_output');

                    return $run;
                }

                $this->circuit->recordSuccess($provider->name());

                $this->finishRun($run, AiRunStatus::Succeeded, [
                    'result' => $validation['data'],
                    'confidence' => isset($validation['data']['confidence']) ? (float) $validation['data']['confidence'] : null,
                    'latency_ms' => $result->latencyMs,
                    'prompt_tokens' => $result->promptTokens,
                    'completion_tokens' => $result->completionTokens,
                    'total_tokens' => $result->totalTokens,
                    'estimated_cost' => $result->estimatedCost(),
                    'model' => $result->model,
                ]);

                $this->logOutcome($run, 'ai.succeeded');

                return $run;
            } catch (AiProviderException $exception) {
                if ($exception->countsTowardsCircuit()) {
                    $this->circuit->recordFailure($provider->name());
                }

                $this->finishRun($run, AiRunStatus::Failed, [
                    'error_code' => $exception->errorCode,
                    // Mensagem operacional: nunca corpo da mensagem, telefone ou chave.
                    'error_message' => mb_substr($exception->getMessage(), 0, 255),
                ]);

                $this->logOutcome($run, 'ai.failed');

                if (! $exception->isRetryable() || $attempt >= $maxAttempts) {
                    return $run;
                }

                if ($backoffMs > 0) {
                    usleep($backoffMs * 1000 * $attempt);
                }
            }
        }

        return $run;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function startRun(
        AiRunPurpose $purpose,
        string $provider,
        string $model,
        string $promptVersion,
        int $schemaVersion,
        string $hash,
        int $attempt,
        array $context,
    ): AiRun {
        return AiRun::create([
            'conversation_id' => $context['conversation_id'] ?? null,
            'source_message_id' => $context['source_message_id'] ?? null,
            'conversation_flow_id' => $context['conversation_flow_id'] ?? null,
            'purpose' => $purpose,
            'provider' => $provider,
            'model' => $model,
            'prompt_version' => $promptVersion,
            'schema_version' => $schemaVersion,
            'status' => AiRunStatus::Running,
            'request_hash' => $hash,
            'attempt' => $attempt,
            'started_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function finishRun(AiRun $run, AiRunStatus $status, array $attributes = []): void
    {
        $run->forceFill(array_merge($attributes, [
            'status' => $status,
            'completed_at' => now(),
        ]))->save();
    }

    /**
     * Log técnico com identificadores e códigos apenas.
     */
    private function logOutcome(AiRun $run, string $event): void
    {
        Log::info($event, [
            'run_id' => $run->id,
            'purpose' => $run->purpose->value,
            'provider' => $run->provider,
            'model' => $run->model,
            'status' => $run->status->value,
            'attempt' => $run->attempt,
            'latency_ms' => $run->latency_ms,
            'error_code' => $run->error_code,
            'conversation_id' => $run->conversation_id,
            'source_message_id' => $run->source_message_id,
        ]);
    }
}
