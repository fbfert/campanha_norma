<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InsightTopic extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'description',
        'response_guidance',
        'red_lines',
        'synonyms',
        'color',
        'display_order',
        'is_active',
        'is_fallback',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'is_active' => 'boolean',
            'is_fallback' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('display_order');
    }

    public function insights(): HasMany
    {
        return $this->hasMany(ConversationInsight::class, 'insight_topic_id');
    }

    /**
     * Sinônimos cadastrados, separados por barra vertical.
     *
     * @return array<int, string>
     */
    public function synonymList(): array
    {
        return collect(explode('|', (string) $this->synonyms))
            ->map(fn (string $item): string => trim($item))
            ->filter(fn (string $item): bool => $item !== '')
            ->values()
            ->all();
    }

    /**
     * Um tema esta em uso quando já foi atribuído a algum insight, como tema
     * principal ou secundário. Temas em uso nunca são excluídos.
     */
    public function isInUse(): bool
    {
        return $this->insights()->exists()
            || ConversationInsightTopic::where('insight_topic_id', $this->id)->exists()
            || $this->children()->exists();
    }
}
