<?php

namespace App\Models;

use App\Enums\GroundingStatus;
use App\Enums\HandoffReason;
use App\Enums\ReplySuggestionAction;
use App\Enums\ReplySuggestionStatus;
use App\Enums\ResponseGenerationMode;
use App\Enums\SuggestionFeedback;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sugestão de resposta gerada com apoio de IA.
 *
 * O texto gerado nunca e sobrescrito: a edição do operador vai para `final_text`
 * e os dois ficam disponíveis para auditoria.
 */
class ConversationReplySuggestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'conversation_flow_state_id',
        'conversation_flow_id',
        'source_message_id',
        'active_source_message_id',
        'ai_run_id',
        'conversation_insight_id',
        'classification_id',
        'status',
        'action',
        'follow_up_type',
        'insight_topic_id',
        'generated_text',
        'final_text',
        'confidence',
        'grounded',
        'grounding_status',
        'grounding_error',
        'citation_count',
        'knowledge_retrieval_id',
        'requires_human_review',
        'handoff_reason',
        'validation_error',
        'blocked_reason',
        'mode',
        'prompt_version',
        'schema_version',
        'turn_number',
        'generation_attempt',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'regeneration_reason',
        'sent_message_id',
        'sent_at',
        'auto_sent',
        'expires_at',
        'feedback',
        'feedback_reason',
        'feedback_by',
        'feedback_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReplySuggestionStatus::class,
            'action' => ReplySuggestionAction::class,
            'handoff_reason' => HandoffReason::class,
            'mode' => ResponseGenerationMode::class,
            'feedback' => SuggestionFeedback::class,
            'confidence' => 'float',
            'grounded' => 'boolean',
            'grounding_status' => GroundingStatus::class,
            'citation_count' => 'integer',
            'requires_human_review' => 'boolean',
            'auto_sent' => 'boolean',
            'schema_version' => 'integer',
            'turn_number' => 'integer',
            'generation_attempt' => 'integer',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'feedback_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(ConversationFlowState::class, 'conversation_flow_state_id');
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(ConversationFlow::class, 'conversation_flow_id');
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'source_message_id');
    }

    public function sentMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'sent_message_id');
    }

    public function insight(): BelongsTo
    {
        return $this->belongsTo(ConversationInsight::class, 'conversation_insight_id');
    }

    public function classification(): BelongsTo
    {
        return $this->belongsTo(ConversationMessageClassification::class, 'classification_id');
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(InsightTopic::class, 'insight_topic_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function retrieval(): BelongsTo
    {
        return $this->belongsTo(KnowledgeRetrieval::class, 'knowledge_retrieval_id');
    }

    public function citations(): HasMany
    {
        return $this->hasMany(ReplySuggestionCitation::class);
    }

    /**
     * Texto que efetivamente vai para o contato.
     */
    public function outgoingText(): string
    {
        return trim((string) ($this->final_text ?? $this->generated_text));
    }

    public function wasEdited(): bool
    {
        return $this->final_text !== null && trim((string) $this->final_text) !== trim((string) $this->generated_text);
    }

    /**
     * Obsoleta quando já existe mensagem recebida mais nova que a de origem.
     */
    public function isStale(): bool
    {
        $latestIncomingId = ConversationMessage::query()
            ->where('conversation_id', $this->conversation_id)
            ->where('direction', 'incoming')
            ->max('id');

        return $latestIncomingId !== null && (int) $latestIncomingId > (int) $this->source_message_id;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
