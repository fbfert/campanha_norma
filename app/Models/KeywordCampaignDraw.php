<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * O registro de um sorteio.
 *
 * Guarda tudo o que alguém de fora precisa para refazer a conta: qual lista,
 * qual semente, quantos, e o resultado na ordem em que saiu. A semente fica em
 * claro de propósito — semente em segredo não serve de auditoria nenhuma.
 */
class KeywordCampaignDraw extends Model
{
    use HasFactory;

    protected $fillable = [
        'keyword_campaign_id',
        'list_hash',
        'seed',
        'quantity',
        'result',
        'executed_by',
        'executed_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'quantity' => 'integer',
            'executed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(KeywordCampaign::class, 'keyword_campaign_id');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    /**
     * As participações sorteadas, na ordem do sorteio.
     *
     * O `whereIn` devolve em ordem de banco, e a ordem é parte do resultado:
     * é ela que alguém de fora refaz com a semente e a lista. Todos os sorteados
     * são ganhadores — a ordem não separa ganhador de suplente. Por isso a
     * reordenação pelo que está gravado, e não pelo que o banco devolveu.
     *
     * @return Collection<int, KeywordCampaignParticipation>
     */
    public function participacoesSorteadas(): Collection
    {
        $ids = collect($this->result ?? [])->map(fn ($id): int => (int) $id);

        $participacoes = KeywordCampaignParticipation::query()
            ->with('contact')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return $ids
            ->map(fn (int $id) => $participacoes->get($id))
            ->filter()
            ->values();
    }
}
