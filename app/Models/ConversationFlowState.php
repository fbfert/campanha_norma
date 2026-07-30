<?php

namespace App\Models;

use App\Enums\ConversationFlowStage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConversationFlowState extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'conversation_flow_id',
        'current_stage',
        'selected_question_id',
        'selected_question_snapshot',
        'automated_messages_count',
        'followups_count',
        'attempts_count',
        'last_processed_message_id',
        'last_automated_message_id',
        'end_reason',
        'is_paused',
        'needs_human_review',
        'started_at',
        'last_transition_at',
        'last_suggestion_at',
        'completed_at',
        'expires_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'current_stage' => ConversationFlowStage::class,
            'automated_messages_count' => 'integer',
            'followups_count' => 'integer',
            'attempts_count' => 'integer',
            'is_paused' => 'boolean',
            'needs_human_review' => 'boolean',
            'started_at' => 'datetime',
            'last_transition_at' => 'datetime',
            'last_suggestion_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(ConversationFlow::class, 'conversation_flow_id');
    }

    public function selectedQuestion(): BelongsTo
    {
        return $this->belongsTo(ConversationFlowQuestion::class, 'selected_question_id');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(ConversationFlowTransition::class)->latest('created_at');
    }

    public function questionUsages(): HasMany
    {
        return $this->hasMany(ConversationFlowQuestionUsage::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
