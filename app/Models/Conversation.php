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
use Illuminate\Database\Eloquent\SoftDeletes;

class Conversation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contact_id',
        'connection_id',
        'status',
        'priority',
        'assigned_user_id',
        'last_message_direction',
        'last_message_at',
        'last_incoming_message_at',
        'last_outgoing_message_at',
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
}
