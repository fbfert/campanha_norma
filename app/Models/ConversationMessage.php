<?php

namespace App\Models;

use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageOrigin;
use App\Enums\ConversationMessageStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
