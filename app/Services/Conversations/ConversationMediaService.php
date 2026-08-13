<?php

namespace App\Services\Conversations;

use App\Enums\ConversationMessageDirection;
use App\Enums\MediaStorageStatus;
use App\Models\ConversationMessage;
use App\Models\ConversationMessageMedium;
use App\Services\SystemSettingService;
use App\Services\WhatsApp\WhatsAppServiceClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Traz a mídia da sessão do WhatsApp para o disco, quando alguém precisa dela.
 *
 * Preguiçoso de propósito. Baixar tudo que chega seria puxar, numa única
 * sincronização de trinta dias, centenas de arquivos que ninguém vai abrir — e
 * cada um deles passa pelo Puppeteer, dentro do mesmo processo que mantém a
 * sessão de pé. Quem precisa primeiro busca: o operador que abriu a conversa,
 * ou a visão que vai descrever a imagem para o fluxo responder.
 *
 * O que se guarda é o arquivo. O prazo de retenção apaga o arquivo e mantém o
 * registro, porque é foto de gente que está no nosso disco.
 */
class ConversationMediaService
{
    /** Diretório dentro do disco privado. */
    private const DIRECTORY = 'conversation-attachments';

    public function __construct(
        private readonly WhatsAppServiceClient $whatsapp,
        private readonly SystemSettingService $settings,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('conversations.media_storage_enabled', '0');
    }

    /**
     * Mídia pronta para uso, buscando agora se ainda não estiver em disco.
     *
     * Devolve `null` quando não há o que entregar — e o registro guarda o
     * porquê, para a tela dizer "não foi possível recuperar" em vez de mostrar
     * um quadrado quebrado.
     */
    public function ensure(ConversationMessage $message): ?ConversationMessageMedium
    {
        // A chave existia e não era consultada aqui: ela aparecia na
        // configuração prometendo desligar o armazenamento, e não desligava
        // nada. Configuração que mente é pior que configuração que falta.
        if (! $this->enabled() || ! $this->handles($message)) {
            return null;
        }

        $medium = ConversationMessageMedium::firstOrCreate(
            ['conversation_message_id' => $message->id],
            ['conversation_id' => $message->conversation_id, 'status' => MediaStorageStatus::Pending],
        );

        if ($medium->isAvailable()) {
            return $medium;
        }

        if (! $this->shouldRetry($medium)) {
            return null;
        }

        /*
         | Uma busca por mensagem de cada vez.
         |
         | Abrir a conversa dispara uma requisição por imagem visível, e um
         | recarregar impaciente dispara tudo de novo. Sem a trava, o mesmo
         | arquivo entraria três vezes no Puppeteer — que é justamente o
         | processo que não se pode afogar, porque é ele que segura a sessão.
         */
        $lock = Cache::lock("conversation-attachment:{$message->id}", 60);

        if (! $lock->get()) {
            return $medium->refresh()->isAvailable() ? $medium : null;
        }

        try {
            return $this->fetch($message, $medium->refresh());
        } finally {
            $lock->release();
        }
    }

    /**
     * Mensagem recebida que traz arquivo.
     *
     * Saída nossa fica de fora: o que mandamos já está registrado como texto, e
     * não há arquivo do outro lado para buscar.
     */
    public function handles(ConversationMessage $message): bool
    {
        return $message->direction === ConversationMessageDirection::Incoming
            && $message->has_media
            && filled($message->external_message_id);
    }

    private function fetch(ConversationMessage $message, ConversationMessageMedium $medium): ?ConversationMessageMedium
    {
        if ($medium->isAvailable()) {
            return $medium;
        }

        $chatId = $message->external_chat_id ?: $message->conversation?->external_chat_id;

        if (blank($chatId)) {
            return $this->fail($medium, 'ATTACHMENT_CHAT_MISSING', 'Mensagem sem identificador de conversa no provedor.');
        }

        $maxBytes = (int) $this->settings->get('conversations.media_max_bytes', 16777216);

        try {
            $payload = $this->whatsapp->fetchMessageMedia($chatId, (string) $message->external_message_id, [
                'maxBytes' => $maxBytes,
            ]);
        } catch (Throwable $exception) {
            // Mídia indisponível não é erro de operação: a sessão do WhatsApp
            // guarda por tempo limitado, e arquivo antigo simplesmente não
            // volta. Fica registrado e a tela diz isso.
            Log::warning('conversation_attachment.unavailable', [
                'message_id' => $message->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->fail($medium, 'ATTACHMENT_UNAVAILABLE', mb_substr($exception->getMessage(), 0, 500));
        }

        $binary = base64_decode((string) ($payload['data'] ?? ''), true);

        if ($binary === false || $binary === '') {
            return $this->fail($medium, 'ATTACHMENT_EMPTY', 'O provedor devolveu a mídia sem conteúdo.');
        }

        if (strlen($binary) > $maxBytes) {
            $medium->forceFill([
                'status' => MediaStorageStatus::TooLarge,
                'size_bytes' => strlen($binary),
                'attempt' => $medium->attempt + 1,
                'error_code' => 'ATTACHMENT_TOO_LARGE',
                'error_message' => 'Arquivo acima do teto configurado.',
            ])->save();

            return null;
        }

        $mimetype = (string) ($payload['mimetype'] ?? 'application/octet-stream');
        $path = $this->pathFor($message, $mimetype, (string) ($payload['filename'] ?? ''));

        Storage::disk('local')->put($path, $binary);

        $days = max(1, (int) $this->settings->get('conversations.media_retention_days', 90));

        $medium->forceFill([
            'status' => MediaStorageStatus::Stored,
            'disk' => 'local',
            'path' => $path,
            'mimetype' => $mimetype,
            'filename' => ($payload['filename'] ?? null) ?: null,
            'size_bytes' => strlen($binary),
            'sha256' => hash('sha256', $binary),
            'error_code' => null,
            'error_message' => null,
            'attempt' => $medium->attempt + 1,
            'fetched_at' => now(),
            'purge_after' => now()->addDays($days),
            'purged_at' => null,
        ])->save();

        return $medium->refresh();
    }

    /**
     * Apaga o arquivo e mantém o registro.
     *
     * Apagar a linha junto faria a conversa fingir que nunca houve foto
     * nenhuma. O que se quer é o contrário: dizer que havia, e que expirou.
     */
    public function purge(ConversationMessageMedium $medium): bool
    {
        if (filled($medium->path)) {
            Storage::disk($medium->disk ?: 'local')->delete($medium->path);
        }

        return $medium->forceFill([
            'status' => MediaStorageStatus::Purged,
            'path' => null,
            'purged_at' => now(),
        ])->save();
    }

    /**
     * Já tentou demais?
     *
     * Mídia que a sessão não devolve continua não devolvendo, e cada tentativa
     * é uma ida ao Puppeteer. O teto existe pela mesma razão que o da rede de
     * segurança: sem ele, a tela reabre a tentativa a cada carregamento, para
     * sempre.
     */
    private function shouldRetry(ConversationMessageMedium $medium): bool
    {
        if (! $medium->status->isRetryable()) {
            return false;
        }

        return $medium->attempt < max(1, (int) $this->settings->get('conversations.media_max_attempts', 3));
    }

    private function fail(ConversationMessageMedium $medium, string $code, string $message): null
    {
        $medium->forceFill([
            'status' => MediaStorageStatus::Unavailable,
            'attempt' => $medium->attempt + 1,
            'error_code' => $code,
            'error_message' => mb_substr($message, 0, 500),
        ])->save();

        return null;
    }

    /**
     * Caminho no disco privado.
     *
     * O nome não sai do que o remetente mandou: `filename` vem de fora, e nome
     * de arquivo vindo de fora é como se escreve fora do diretório pretendido.
     * A extensão sai do mimetype, e o resto é o identificador da mensagem.
     */
    private function pathFor(ConversationMessage $message, string $mimetype, string $filename): string
    {
        $extension = match (true) {
            str_contains($mimetype, 'jpeg'), str_contains($mimetype, 'jpg') => 'jpg',
            str_contains($mimetype, 'png') => 'png',
            str_contains($mimetype, 'webp') => 'webp',
            str_contains($mimetype, 'gif') => 'gif',
            str_contains($mimetype, 'ogg') => 'ogg',
            str_contains($mimetype, 'mpeg'), str_contains($mimetype, 'mp3') => 'mp3',
            str_contains($mimetype, 'mp4') => 'mp4',
            str_contains($mimetype, 'pdf') => 'pdf',
            default => Str::substr(preg_replace('/[^a-z0-9]/', '', Str::lower(Str::afterLast($filename, '.'))) ?: 'bin', 0, 8),
        };

        return self::DIRECTORY.'/'.$message->conversation_id.'/'.$message->id.'.'.$extension;
    }
}
