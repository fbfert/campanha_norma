<?php

namespace App\Models;

use App\Enums\AiRunPurpose;
use App\Enums\AiRunStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log append-only de cada tentativa de execucao de IA.
 *
 * Nunca guarda credencial, cabecalho de autorizacao ou payload desnecessario.
 */
class AiRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'source_message_id',
        'conversation_flow_id',
        'purpose',
        'provider',
        'model',
        'prompt_version',
        'schema_version',
        'status',
        'request_hash',
        'result',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'latency_ms',
        'estimated_cost',
        'confidence',
        'error_code',
        'error_message',
        'attempt',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'purpose' => AiRunPurpose::class,
            'status' => AiRunStatus::class,
            'schema_version' => 'integer',
            'result' => 'array',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'latency_ms' => 'integer',
            'estimated_cost' => 'float',
            'confidence' => 'float',
            'attempt' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sourceMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'source_message_id');
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(ConversationFlow::class, 'conversation_flow_id');
    }

    public function succeeded(): bool
    {
        return $this->status === AiRunStatus::Succeeded;
    }
}
