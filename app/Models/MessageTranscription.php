<?php

namespace App\Models;

use App\Enums\TranscriptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Texto extraído de um áudio recebido.
 *
 * O que a pessoa falou não vira o corpo da mensagem: fica aqui, marcado como
 * transcrição, com o modelo que produziu e o custo associado. Quem lê a
 * conversa precisa conseguir distinguir o que foi escrito do que foi ouvido por
 * uma máquina — numa pesquisa, essa diferença muda o peso do dado.
 */
class MessageTranscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'conversation_message_id',
        'ai_run_id',
        'status',
        'provider',
        'model',
        'media_type',
        'text',
        'language',
        'duration_seconds',
        'media_bytes',
        'error_code',
        'error_message',
        'attempt',
        'transcribed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TranscriptionStatus::class,
            'duration_seconds' => 'integer',
            'media_bytes' => 'integer',
            'attempt' => 'integer',
            'transcribed_at' => 'datetime',
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

    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }

    public function usableAsAnswer(): bool
    {
        return $this->status->usableAsAnswer() && filled($this->text);
    }
}
