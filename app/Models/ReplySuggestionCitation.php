<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fonte usada por uma sugestão fundamentada.
 *
 * Citação invalida também e persistida, com o motivo: saber que o modelo citou
 * algo que não existia e informação de auditoria, não ruído a descartar.
 */
class ReplySuggestionCitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_reply_suggestion_id',
        'knowledge_retrieval_chunk_id',
        'knowledge_document_id',
        'document_title_snapshot',
        'document_version',
        'chunk_reference',
        'content_snapshot',
        'page',
        'section',
        'score',
        'is_valid',
        'invalid_reason',
    ];

    protected function casts(): array
    {
        return [
            'document_version' => 'integer',
            'page' => 'integer',
            'score' => 'float',
            'is_valid' => 'boolean',
        ];
    }

    public function suggestion(): BelongsTo
    {
        return $this->belongsTo(ConversationReplySuggestion::class, 'conversation_reply_suggestion_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }
}
