<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationFlowQuestionUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_flow_state_id',
        'conversation_id',
        'conversation_flow_question_id',
        'question_snapshot',
        'selected_at',
        'sent_at',
        'conversation_message_id',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'selected_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(ConversationFlowState::class, 'conversation_flow_state_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ConversationFlowQuestion::class, 'conversation_flow_question_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'conversation_message_id');
    }
}
