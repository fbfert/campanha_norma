<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trecho efetivamente devolvido por uma recuperação.
 *
 * Guarda snapshot de conteúdo, título e versão. A chave estrangeira permite
 * navegar; o snapshot permite auditar depois de o documento ter sido substituído
 * ou excluído.
 */
class KnowledgeRetrievalChunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'knowledge_retrieval_id',
        'knowledge_chunk_id',
        'knowledge_document_id',
        'document_title_snapshot',
        'document_version',
        'chunk_reference',
        'content_snapshot',
        'page',
        'section',
        'score',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'document_version' => 'integer',
            'page' => 'integer',
            'score' => 'float',
            'position' => 'integer',
        ];
    }

    public function retrieval(): BelongsTo
    {
        return $this->belongsTo(KnowledgeRetrieval::class, 'knowledge_retrieval_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(KnowledgeChunk::class, 'knowledge_chunk_id');
    }
}
