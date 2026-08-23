<?php

namespace App\Models;

use App\Enums\KeywordCouponStatus;
use App\Support\MantemChaveDeLixeira;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Um cupom do prêmio.
 *
 * O código é valor e não sai daqui em claro. `reference` existe justamente para
 * dar ao resto do sistema — histórico, log, auditoria — algo que identifique o
 * cupom sem entregá-lo.
 */
class KeywordCampaignCoupon extends Model
{
    use HasFactory, MantemChaveDeLixeira, SoftDeletes;

    protected $fillable = [
        'keyword_campaign_id',
        'code',
        'status',
        'keyword_campaign_participation_id',
        'assigned_at',
        'delivered_at',
        'reference',
        'imported_by',
    ];

    /**
     * O código nunca vai junto numa serialização acidental.
     *
     * `toArray()` alimenta log estruturado, resposta JSON e `dd()`. Esconder
     * aqui é o que impede o código vazar por um caminho que ninguém lembrou de
     * revisar.
     *
     * @var list<string>
     */
    protected $hidden = ['code'];

    protected function casts(): array
    {
        return [
            'status' => KeywordCouponStatus::class,
            'assigned_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(KeywordCampaign::class, 'keyword_campaign_id');
    }

    public function participation(): BelongsTo
    {
        return $this->belongsTo(KeywordCampaignParticipation::class, 'keyword_campaign_participation_id');
    }

    public function scopeDisponivel(Builder $query): Builder
    {
        return $query
            ->where('status', KeywordCouponStatus::Disponivel)
            ->whereNull('keyword_campaign_participation_id');
    }
}
