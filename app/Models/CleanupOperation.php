<?php

namespace App\Models;

use App\Enums\CleanupTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma limpeza executada.
 *
 * A operação e a unidade da lixeira: quem limpou, de quem, o que, por quê e
 * até quando dá para voltar atrás. O telefone e o nome ficam em snapshot
 * porque a lixeira precisa continuar legível mesmo que o contato seja excluído
 * depois pela tela de Contatos.
 */
class CleanupOperation extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'contact_name_snapshot',
        'contact_phone_snapshot',
        'targets',
        'reason',
        'items_count',
        'involved_draw',
        'executed_by',
        'executed_at',
        'expires_at',
        'restored_by',
        'restored_at',
        'purged_at',
    ];

    protected function casts(): array
    {
        return [
            'targets' => 'array',
            'items_count' => 'integer',
            'involved_draw' => 'boolean',
            'executed_at' => 'datetime',
            'expires_at' => 'datetime',
            'restored_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(CleanupItem::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function restorer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }

    /**
     * Ainda dá para restaurar.
     *
     * Vencido o prazo, a rotina diária apaga em definitivo — mas entre o
     * vencimento e a próxima passagem da rotina existe uma janela em que a
     * linha continua lá. Recusar a restauração já no vencimento e o que faz o
     * prazo valer para quem opera, e não só para o agendador.
     */
    public function podeRestaurar(): bool
    {
        return $this->restored_at === null
            && $this->purged_at === null
            && $this->expires_at->isFuture();
    }

    public function scopeVencidas(Builder $query): Builder
    {
        return $query->whereNull('purged_at')
            ->whereNull('restored_at')
            ->where('expires_at', '<=', now());
    }

    public function scopeNaLixeira(Builder $query): Builder
    {
        return $query->whereNull('purged_at')->whereNull('restored_at');
    }

    /**
     * @return list<CleanupTarget>
     */
    public function alvos(): array
    {
        return array_values(array_filter(array_map(
            fn (string $valor): ?CleanupTarget => CleanupTarget::tryFrom($valor),
            $this->targets ?? []
        )));
    }
}
