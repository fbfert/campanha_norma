<?php

namespace App\Models;

use App\Enums\ClassificationSource;
use App\Enums\MessageClassification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConversationMessageClassification extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'conversation_message_id',
        'purpose',
        'classification',
        'source',
        'confidence',
        'requires_human_review',
        'review_reason',
        'prompt_version',
        'schema_version',
        'ai_run_id',
    ];

    protected function casts(): array
    {
        return [
            'classification' => MessageClassification::class,
            'source' => ClassificationSource::class,
            'confidence' => 'float',
            'requires_human_review' => 'boolean',
            'schema_version' => 'integer',
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

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(ConversationInsightCorrection::class, 'conversation_message_classification_id')
            ->latest('created_at');
    }
}
