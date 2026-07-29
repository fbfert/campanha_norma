<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationInsightTopic extends Model
{
    protected $fillable = [
        'conversation_insight_id',
        'insight_topic_id',
        'role',
        'raw_value',
    ];

    public function insight(): BelongsTo
    {
        return $this->belongsTo(ConversationInsight::class, 'conversation_insight_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(InsightTopic::class, 'insight_topic_id');
    }
}
