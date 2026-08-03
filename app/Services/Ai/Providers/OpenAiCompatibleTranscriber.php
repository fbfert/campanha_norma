<?php

namespace App\Services\Ai\Providers;

use App\Contracts\AudioTranscriber;
use App\Data\Ai\TranscriptionResult;
use App\Exceptions\Ai\AiProviderException;
use App\Services\Ai\AiProviderSettings;
use App\Services\SystemSettingService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Transcrição pelo endpoint `/audio/transcriptions`.
 *
 * Protocolo diferente do resto: o arquivo vai em multipart, e não em JSON, e a
 * resposta e texto simples em vez de objeto validado por schema. Por isso o
 * contrato e separado do provedor de texto.
 */
class OpenAiCompatibleTranscriber implements AudioTranscriber
{
    public function __construct(
        private readonly AiProviderSettings $provider,
        private readonly SystemSettingService $settings,
    ) {}

    public function transcribe(string $audio, string $filename): TranscriptionResult
    {
        $key = $this->provider->key('ai.key');

        if ($key === null || $key === '') {
            throw new AiProviderException(AiProviderException::UNAUTHORIZED, 'Credencial de IA ausente para transcrição.');
        }

        $model = (string) $this->settings->get('ai.transcription.model', 'whisper-1');
        $inicio = microtime(true);

        try {
            $response = Http::baseUrl((string) config('ai.providers.openai.url'))
                ->withToken($key)
                ->timeout((int) $this->settings->get('ai.transcription.timeout', 120))
                ->attach('file', $audio, $filename)
                ->post('/audio/transcriptions', [
                    'model' => $model,
                    // O idioma declarado melhora bastante o resultado em áudio
                    // curto e com ruído, que e o caso de nota de voz.
                    'language' => (string) $this->settings->get('ai.transcription.language', 'pt'),
                    'response_format' => 'verbose_json',
                ]);
        } catch (ConnectionException $excecao) {
            throw new AiProviderException(
                str_contains($excecao->getMessage(), 'timed out') ? AiProviderException::TIMEOUT : AiProviderException::SERVICE_UNAVAILABLE,
                'Provedor de transcrição indisponível.',
            );
        }

        if (! $response->successful()) {
            throw new AiProviderException(
                match (true) {
                    in_array($response->status(), [401, 403], true) => AiProviderException::UNAUTHORIZED,
                    $response->status() === 429 => AiProviderException::RATE_LIMITED,
                    default => AiProviderException::SERVICE_UNAVAILABLE,
                },
                'Falha na transcrição: HTTP '.$response->status().'.',
            );
        }

        $dados = $response->json();

        return new TranscriptionResult(
            text: (string) ($dados['text'] ?? ''),
            model: (string) ($dados['model'] ?? $model),
            language: isset($dados['language']) ? (string) $dados['language'] : null,
            durationSeconds: isset($dados['duration']) ? (int) round((float) $dados['duration']) : null,
            latencyMs: (int) round((microtime(true) - $inicio) * 1000),
        );
    }
}
