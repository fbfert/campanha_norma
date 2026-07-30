<?php

namespace App\Models;

use App\Enums\RetrievalStrategy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeRetrieval extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'source_message_id',
        'conversation_flow_id',
        'ai_run_id',
        'query_text',
        'strategy',
        'top_k',
        'threshold',
        'candidate_count',
        'returned_count',
        'max_score',
        'min_score',
        'duration_ms',
        'provider',
        'degraded_reason',
        'is_test',
    ];

    protected function casts(): array
    {
        return [
            'strategy' => RetrievalStrategy::class,
            'top_k' => 'integer',
            'threshold' => 'float',
            'candidate_count' => 'integer',
            'returned_count' => 'integer',
            'max_score' => 'float',
            'min_score' => 'float',
            'duration_ms' => 'integer',
            'is_test' => 'boolean',
        ];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeRetrievalChunk::class)->orderBy('position');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'ai_run_id');
    }
}
