<?php

namespace App\Models;

use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Conversation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contact_id',
        'connection_id',
        'provider',
        'external_chat_id',
        'status',
        'priority',
        'assigned_user_id',
        'last_message_direction',
        'last_message_at',
        'last_incoming_message_at',
        'last_outgoing_message_at',
        'last_synced_at',
        'first_response_at',
        'unread_count',
        'is_archived',
        'archived_at',
        'archived_by',
        'closed_at',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'priority' => ConversationPriority::class,
            'last_message_direction' => ConversationMessageDirection::class,
            'last_message_at' => 'datetime',
            'last_incoming_message_at' => 'datetime',
            'last_outgoing_message_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'first_response_at' => 'datetime',
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->latest('created_at');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ConversationMessage::class)->latestOfMany();
    }

    public function events(): HasMany
    {
        return $this->hasMany(ConversationEvent::class)->latest('created_at');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ConversationNote::class)->latest('created_at');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ConversationAssignment::class)->latest('assigned_at');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(ConversationTag::class, 'conversation_conversation_tag')->withPivot('created_by')->withTimestamps();
    }

    public function flowState(): HasOne
    {
        return $this->hasOne(ConversationFlowState::class);
    }

    public function insights(): HasMany
    {
        return $this->hasMany(ConversationInsight::class)->latest('id');
    }

    public function messageClassifications(): HasMany
    {
        return $this->hasMany(ConversationMessageClassification::class)->latest('id');
    }

    public function whatsappPhoneDigits(): ?string
    {
        if ($this->contact?->phone_normalized) {
            return $this->contact->phone_normalized;
        }

        $externalChatId = (string) ($this->external_chat_id ?? '');
        if ($externalChatId !== '' && Str::contains($externalChatId, '@c.us')) {
            $digits = preg_replace('/\D+/', '', Str::before($externalChatId, '@'));
            if (is_string($digits) && preg_match('/^\d{10,15}$/', $digits)) {
                return $digits;
            }
        }

        $snapshot = $this->messages()
            ->where(fn ($query) => $query->whereNotNull('sender_phone_snapshot')->orWhereNotNull('recipient_phone_snapshot'))
            ->latest('id')
            ->first();

        $digits = $snapshot?->sender_phone_snapshot ?: $snapshot?->recipient_phone_snapshot;
        if (is_string($digits) && preg_match('/^\d{10,15}$/', $digits)) {
            return $digits;
        }

        return null;
    }

    public function whatsappPhoneForDisplay(): ?string
    {
        $digits = $this->whatsappPhoneDigits();
        if (! $digits) {
            return null;
        }

        return $digits;
    }

    public function whatsappIdentifierForDisplay(): ?string
    {
        return $this->external_chat_id ?: null;
    }
}
