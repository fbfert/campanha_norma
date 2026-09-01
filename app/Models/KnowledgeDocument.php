<?php

namespace App\Models;

use App\Enums\KnowledgeBaseStatus;
use App\Enums\KnowledgeDocumentStatus;
use App\Enums\KnowledgeDocumentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'knowledge_base_id',
        'title',
        'type',
        'insight_topic_id',
        'source',
        'source_url',
        'document_date',
        'disk',
        'file_path',
        'original_filename',
        'mime_type',
        'file_size',
        'content_hash',
        'status',
        'version',
        'supersedes_document_id',
        'metadata',
        'extracted_text',
        'chunk_count',
        'indexed_at',
        'provider_file_id',
        'error_message',
        'injection_flagged',
        'injection_findings',
        'antivirus_result',
        'created_by',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'rejection_reason',
        'obsoleted_by',
        'obsoleted_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => KnowledgeDocumentStatus::class,
            'type' => KnowledgeDocumentType::class,
            'document_date' => 'date',
            'metadata' => 'array',
            'file_size' => 'integer',
            'version' => 'integer',
            'chunk_count' => 'integer',
            'injection_flagged' => 'boolean',
            'indexed_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'obsoleted_at' => 'datetime',
        ];
    }

    public function base(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'knowledge_base_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class)->orderBy('chunk_index');
    }

    /**
     * O tema da população a que este documento oficial responde.
     *
     * Existe para a pauta de posicionamento da 9F, e apenas para ela. A
     * recuperação da 9D não lê esta relação: a opinião coletada não decide o
     * que a campanha responde.
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(InsightTopic::class, 'insight_topic_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_document_id');
    }

    public function supersededBy(): HasMany
    {
        return $this->hasMany(self::class, 'supersedes_document_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Documentos que podem participar de uma recuperação.
     *
     * A regra vive aqui, e não no chamador, porque uma condição dessa natureza
     * não deve depender de alguém lembrar de aplica-la. O retriever a reafirma.
     */
    public function scopeRetrievable(Builder $query): Builder
    {
        return $query->where('knowledge_documents.status', KnowledgeDocumentStatus::Approved->value)
            ->whereHas('base', fn (Builder $base) => $base->where('status', KnowledgeBaseStatus::Active->value));
    }

    public function isRetrievable(): bool
    {
        return $this->status->isRetrievable() && ($this->base?->status->isRetrievable() ?? false);
    }
}
