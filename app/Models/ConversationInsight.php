<?php

namespace App\Models;

use App\Enums\InsightSentiment;
use App\Enums\InsightUrgency;
use App\Support\MantemChaveDeLixeira;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Dado derivado e versionado. Nunca substitui a mensagem original.
 */
class ConversationInsight extends Model
{
    use HasFactory, MantemChaveDeLixeira, SoftDeletes;

    protected $fillable = [
        'conversation_id',
        'contact_id',
        'source_message_id',
        'conversation_flow_id',
        'conversation_flow_question_id',
        'question_snapshot',
        'summary',
        'insight_topic_id',
        'main_topic_raw',
        'secondary_topics_raw',
        'identified_problem',
        'suggested_action',
        'desired_result',
        'affected_group',
        'locality_text',
        'locality_normalized',
        'region',
        'urgency',
        'sentiment',
        'keywords',
        'confidence',
        'requires_human_review',
        'review_reason',
        'reviewed',
        'reviewed_by',
        'reviewed_at',
        'extraction_version',
        'prompt_version',
        'ai_run_id',
    ];

    protected function casts(): array
    {
        return [
            'secondary_topics_raw' => 'array',
            'keywords' => 'array',
            'urgency' => InsightUrgency::class,
            'sentiment' => InsightSentiment::class,
            'confidence' => 'float',
            'requires_human_review' => 'boolean',
            'reviewed' => 'boolean',
            'reviewed_at' => 'datetime',
            'extraction_version' => 'integer',
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

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'source_message_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(InsightTopic::class, 'insight_topic_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ConversationFlowQuestion::class, 'conversation_flow_question_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function topicLinks(): HasMany
    {
        return $this->hasMany(ConversationInsightTopic::class);
    }

    public function secondaryTopicLinks(): HasMany
    {
        return $this->topicLinks()->where('role', 'secondary');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(ConversationInsightCorrection::class)->latest('created_at');
    }
}
