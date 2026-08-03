<?php

namespace App\Models;

use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageOrigin;
use App\Enums\ConversationMessageStatus;
use App\Enums\TranscriptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ConversationMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'contact_id',
        'message_batch_recipient_id',
        'direction',
        'message_type',
        'provider',
        'origin',
        'generated_by_ai',
        'ai_run_id',
        'ai_prompt_version',
        'ai_confidence',
        'approved_by',
        'approved_at',
        'automation_state_transition_id',
        'external_chat_id',
        'external_message_id',
        'event_id',
        'request_id',
        'sender_phone_snapshot',
        'recipient_phone_snapshot',
        'sender_name_snapshot',
        'body',
        'has_media',
        'media_metadata',
        'quoted_message_id',
        'status',
        'sent_at',
        'received_at',
        'read_at',
        'failed_at',
        'error_code',
        'error_message',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'direction' => ConversationMessageDirection::class,
            'origin' => ConversationMessageOrigin::class,
            'generated_by_ai' => 'boolean',
            'ai_confidence' => 'float',
            'approved_at' => 'datetime',
            'status' => ConversationMessageStatus::class,
            'has_media' => 'boolean',
            'media_metadata' => 'array',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function transcriptions(): HasMany
    {
        return $this->hasMany(MessageTranscription::class)->latest('id');
    }

    /**
     * Transcrição que vale para esta mensagem.
     */
    public function transcription(): ?MessageTranscription
    {
        return $this->transcriptions()
            ->where('status', TranscriptionStatus::Succeeded)
            ->first();
    }

    /**
     * O texto que representa esta mensagem para quem precisa lê-la.
     *
     * Áudio chega com corpo vazio. Onde ha transcrição aproveitável, ela ocupa
     * esse lugar — sem sobrescrever o corpo, que continua sendo o registro do
     * que de fato chegou.
     */
    public function readableText(): string
    {
        if (filled($this->body)) {
            return (string) $this->body;
        }

        return (string) ($this->transcription()?->text ?? '');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(MessageBatchRecipient::class, 'message_batch_recipient_id');
    }

    public function classifications(): HasMany
    {
        return $this->hasMany(ConversationMessageClassification::class)->latest('id');
    }

    public function insights(): HasMany
    {
        return $this->hasMany(ConversationInsight::class, 'source_message_id')->latest('extraction_version');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function suggestion(): HasOne
    {
        return $this->hasOne(ConversationReplySuggestion::class, 'sent_message_id');
    }
}
