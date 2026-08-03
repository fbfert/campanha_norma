<?php

namespace App\Services\Ai;

use App\Contracts\AudioTranscriber;
use App\Enums\AiRunPurpose;
use App\Enums\TranscriptionStatus;
use App\Models\AiRun;
use App\Models\ConversationMessage;
use App\Models\MessageTranscription;
use App\Services\SystemSettingService;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Guarda o que foi dito num áudio recebido.
 *
 * O texto não substitui o corpo da mensagem: fica em tabela própria, marcado
 * como transcrição, com o modelo que produziu e o custo associado. Quem lê a
 * conversa precisa distinguir o que a pessoa escreveu do que uma máquina ouviu
 * — numa pesquisa, essa diferença muda o peso do dado.
 */
class AudioTranscriptionService
{
    public function __construct(
        private readonly AudioTranscriber $transcriber,
        private readonly SystemSettingService $settings,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('ai.transcription.enabled', '0');
    }

    /**
     * Transcreve o áudio de uma mensagem e registra o resultado.
     *
     * Idempotente: mensagem que já tem transcrição aproveitável não chama o
     * provedor de novo. Refazer exige `$force`, e a anterior fica marcada como
     * substituída em vez de sumir.
     */
    public function transcribe(ConversationMessage $message, string $audio, string $filename, bool $force = false): ?MessageTranscription
    {
        if (! $this->enabled()) {
            return null;
        }

        $existente = MessageTranscription::query()
            ->where('conversation_message_id', $message->id)
            ->where('status', TranscriptionStatus::Succeeded)
            ->first();

        if ($existente && ! $force) {
            return $existente;
        }

        $registro = MessageTranscription::create([
            'conversation_id' => $message->conversation_id,
            'conversation_message_id' => $message->id,
            'status' => TranscriptionStatus::Pending,
            'media_type' => $message->message_type,
            'media_bytes' => strlen($audio),
            'attempt' => ($existente?->attempt ?? 0) + 1,
        ]);

        try {
            $resultado = $this->transcriber->transcribe($audio, $filename);
        } catch (Throwable $excecao) {
            $registro->forceFill([
                'status' => TranscriptionStatus::Failed,
                'error_code' => 'TRANSCRIPTION_FAILED',
                'error_message' => mb_substr($excecao->getMessage(), 0, 500),
            ])->save();

            return $registro;
        }

        $run = AiRun::create([
            'conversation_id' => $message->conversation_id,
            'source_message_id' => $message->id,
            'purpose' => AiRunPurpose::TranscribeAudio,
            'provider' => (string) config('ai.provider'),
            'model' => $resultado->model,
            'status' => 'succeeded',
            // Transcrição não tem prompt nem schema; as colunas existem para as
            // chamadas de texto e aqui recebem o marcador do que foi usado.
            'prompt_version' => 'transcricao',
            'schema_version' => 1,
            'request_hash' => hash('sha256', 'transcription:'.$message->id.':'.$registro->id),
            'latency_ms' => $resultado->latencyMs,
            'estimated_cost' => $resultado->estimatedCost(),
            'attempt' => $registro->attempt,
            'started_at' => now(),
            'completed_at' => now(),
        ]);

        DB::transaction(function () use ($registro, $resultado, $run, $existente, $force): void {
            if ($force && $existente) {
                $existente->forceFill(['status' => TranscriptionStatus::Superseded])->save();
            }

            $registro->forceFill([
                'ai_run_id' => $run->id,
                // Áudio sem fala não e resposta: fica registrado como vazio, e
                // o fluxo não o trata como opinião.
                'status' => $resultado->isEmpty() ? TranscriptionStatus::Empty : TranscriptionStatus::Succeeded,
                'provider' => (string) config('ai.provider'),
                'model' => $resultado->model,
                'text' => $resultado->isEmpty() ? null : trim($resultado->text),
                'language' => $resultado->language,
                'duration_seconds' => $resultado->durationSeconds,
                'transcribed_at' => now(),
            ])->save();
        });

        return $registro->refresh();
    }
}
