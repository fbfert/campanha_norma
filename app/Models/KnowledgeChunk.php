<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeChunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'knowledge_document_id',
        'knowledge_base_id',
        'chunk_index',
        'external_chunk_id',
        'content',
        'search_text',
        'content_hash',
        'token_estimate',
        'page',
        'section',
        'embedding',
        'embedding_provider',
        'embedding_model',
        'embedding_dimensions',
        'embedded_at',
    ];

    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'token_estimate' => 'integer',
            'page' => 'integer',
            'embedding_dimensions' => 'integer',
            'embedded_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDocument::class, 'knowledge_document_id');
    }

    public function base(): BelongsTo
    {
        return $this->belongsTo(KnowledgeBase::class, 'knowledge_base_id');
    }

    /**
     * Serializa o vetor como floats de 32 bits em ordem de bytes fixa.
     *
     * `pack('g*')` usa little-endian explicito, entao o blob nao depende da
     * arquitetura da maquina que gravou. JSON custaria cerca de trinta vezes mais
     * espaco para a mesma informacao.
     *
     * @param  array<int, float>  $vector
     */
    public static function packEmbedding(array $vector): string
    {
        return pack('g*', ...array_map(static fn ($value): float => (float) $value, $vector));
    }

    /**
     * @return array<int, float>
     */
    public static function unpackEmbedding(?string $blob): array
    {
        if ($blob === null || $blob === '') {
            return [];
        }

        $values = unpack('g*', $blob);

        return $values === false ? [] : array_values($values);
    }

    /**
     * @return array<int, float>
     */
    public function vector(): array
    {
        return self::unpackEmbedding($this->embedding);
    }
}
