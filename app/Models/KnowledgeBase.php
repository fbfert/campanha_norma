<?php

namespace App\Models;

use App\Enums\KnowledgeBaseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeBase extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'purpose',
        'usage_policy',
        'status',
        'version',
        'provider',
        'external_store_id',
        'approved_by',
        'approved_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => KnowledgeBaseStatus::class,
            'version' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }

    public function flows(): BelongsToMany
    {
        return $this->belongsToMany(ConversationFlow::class, 'conversation_flow_knowledge_base')
            ->withPivot('priority')
            ->withTimestamps();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Bases que podem participar de uma recuperação.
     */
    public function scopeRetrievable(Builder $query): Builder
    {
        return $query->where('status', KnowledgeBaseStatus::Active->value);
    }

    public function approvedDocumentCount(): int
    {
        return $this->documents()->where('status', 'approved')->count();
    }
}
