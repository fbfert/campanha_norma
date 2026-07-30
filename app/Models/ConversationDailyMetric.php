<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationDailyMetric extends Model
{
    protected $fillable = [
        'date', 'conversation_flow_id', 'flow_key',
        'approached', 'permission_granted', 'permission_denied', 'opted_out',
        'answers_received', 'completed', 'waiting_human', 'failed',
        'automated_messages', 'conversations_with_turns',
        'first_reply_seconds_total', 'first_reply_samples',
        'rebuilt_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'rebuilt_at' => 'datetime',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(ConversationFlow::class, 'conversation_flow_id');
    }
}
