<?php

namespace App\Models;

use App\Enums\MediaStorageStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Arquivo de uma mídia recebida, e o que se sabe sobre a tentativa de obtê-lo.
 *
 * O registro sobrevive ao arquivo. Passados os noventa dias o conteúdo sai do
 * disco e esta linha continua, marcada como expurgada: é o que permite a
 * conversa dizer "havia uma foto aqui" em vez de fingir que nunca houve.
 */
class ConversationMessageMedium extends Model
{
    use HasFactory;

    protected $table = 'conversation_message_media';

    protected $fillable = [
        'conversation_id',
        'conversation_message_id',
        'status',
        'disk',
        'path',
        'mimetype',
        'filename',
        'size_bytes',
        'sha256',
        'error_code',
        'error_message',
        'attempt',
        'fetched_at',
        'purge_after',
        'purged_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => MediaStorageStatus::class,
            'size_bytes' => 'integer',
            'attempt' => 'integer',
            'fetched_at' => 'datetime',
            'purge_after' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'conversation_message_id');
    }

    /**
     * O arquivo está mesmo lá?
     *
     * A situação diz o que aconteceu na última tentativa; o disco diz o que
     * existe agora. Os dois discordam quando alguém limpa o diretório à mão, e
     * é a tela que paga — `<img>` para um arquivo ausente não avisa nada, só
     * desenha um quadrado quebrado.
     */
    public function isAvailable(): bool
    {
        return $this->status->isReadable()
            && filled($this->path)
            && Storage::disk($this->disk ?: 'local')->exists($this->path);
    }

    /**
     * Já gastou as tentativas de buscar este arquivo?
     */
    public function exhausted(): bool
    {
        $max = max(1, (int) app(\App\Services\SystemSettingService::class)->get('conversations.media_max_attempts', 3));

        return $this->attempt >= $max;
    }

    /**
     * A tela deve explicar a ausência, em vez de tentar mostrar o arquivo?
     *
     * A condição olhava só a situação, e `unavailable` é uma situação que ainda
     * admite nova tentativa — então a tela caía no ramo do `<img>` e apontava
     * para um arquivo que não existe. `<img>` quebrado não avisa nada: quem
     * abre a conversa vê um quadrado cinza e conclui que o sistema está
     * quebrado, quando o que houve foi a mídia não ter vindo.
     */
    public function needsExplanation(): bool
    {
        return ! $this->isAvailable() && (! $this->status->isRetryable() || $this->exhausted());
    }

    public function contents(): ?string
    {
        if (! $this->isAvailable()) {
            return null;
        }

        return Storage::disk($this->disk ?: 'local')->get($this->path);
    }

    /** Imagem e figurinha aparecem na conversa; o resto vira link. */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->mimetype, 'image/');
    }

    public function isAudio(): bool
    {
        return str_starts_with((string) $this->mimetype, 'audio/');
    }

    public function isVideo(): bool
    {
        return str_starts_with((string) $this->mimetype, 'video/');
    }
}
