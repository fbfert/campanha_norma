<?php

namespace App\Models;

use App\Enums\ConversationFlowStatus;
use App\Enums\ConversationQuestionOrder;
use App\Enums\KnowledgeBaseStatus;
use App\Enums\ResponseGenerationMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConversationFlow extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'status',
        'presentation_template_id',
        'presentation_text',
        'thank_you_text',
        'permission_denied_text',
        'max_main_questions',
        'question_order',
        'max_followups',
        'response_mode',
        'validity_hours',
        'transparency_enabled',
        'transparency_text',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConversationFlowStatus::class,
            'response_mode' => ResponseGenerationMode::class,
            'max_main_questions' => 'integer',
            'question_order' => ConversationQuestionOrder::class,
            'max_followups' => 'integer',
            'validity_hours' => 'integer',
            'transparency_enabled' => 'boolean',
        ];
    }

    public function questions(): HasMany
    {
        return $this->hasMany(ConversationFlowQuestion::class)->orderBy('display_order');
    }

    public function activeQuestions(): HasMany
    {
        return $this->questions()->where('is_active', true);
    }

    public function states(): HasMany
    {
        return $this->hasMany(ConversationFlowState::class);
    }

    public function knowledgeBases(): BelongsToMany
    {
        return $this->belongsToMany(KnowledgeBase::class, 'conversation_flow_knowledge_base')
            ->withPivot('priority')
            ->withTimestamps()
            ->orderByDesc('priority');
    }

    /**
     * Bases que efetivamente participam de uma recuperação para este fluxo.
     */
    public function retrievableKnowledgeBases(): BelongsToMany
    {
        return $this->knowledgeBases()->where('knowledge_bases.status', KnowledgeBaseStatus::Active->value);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(MessageBatch::class);
    }

    public function presentationTemplate(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'presentation_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isRunnable(): bool
    {
        return $this->status === ConversationFlowStatus::Active;
    }
}
