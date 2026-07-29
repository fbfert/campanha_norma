<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro imutavel de correcao humana. Preserva o valor original.
 */
class ConversationInsightCorrection extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'conversation_insight_id',
        'conversation_message_classification_id',
        'field',
        'original_value',
        'corrected_value',
        'reason',
        'user_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function insight(): BelongsTo
    {
        return $this->belongsTo(ConversationInsight::class, 'conversation_insight_id');
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(ConversationMessageClassification::class, 'conversation_message_classification_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
