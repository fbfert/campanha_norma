<?php

namespace App\Models;

use App\Enums\InboundAttendanceProfileStatus;
use App\Enums\InboundOpeningMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Perfil de atendimento de entrada.
 *
 * É o equivalente do lote para quem escreve primeiro: diz qual fluxo abrir, o
 * que responder, em que horário e com que teto. O que muda é a seleção — o
 * lote escolhe contatos na base, e aqui quem escolhe é quem escreve.
 */
class InboundAttendanceProfile extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'status',
        'is_fallback',
        'match_expressions',
        'match_priority',
        'conversation_flow_id',
        'opening_mode',
        'presentation_text',
        'window_start',
        'window_end',
        'daily_start_limit',
        'homologation_threshold',
        'approved_starts_count',
        'homologated_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => InboundAttendanceProfileStatus::class,
            'opening_mode' => InboundOpeningMode::class,
            'is_fallback' => 'boolean',
            'match_priority' => 'integer',
            'daily_start_limit' => 'integer',
            'homologation_threshold' => 'integer',
            'approved_starts_count' => 'integer',
            'homologated_at' => 'datetime',
        ];
    }

    public function conversationFlow(): BelongsTo
    {
        return $this->belongsTo(ConversationFlow::class, 'conversation_flow_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(InboundAttendanceAttempt::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Expressões de roteamento, uma por item.
     *
     * Guardadas separadas por barra vertical, do mesmo jeito que as listas da
     * automação conversacional. O formato é o mesmo de propósito: quem edita
     * uma já sabe editar a outra.
     *
     * @return list<string>
     */
    public function matchExpressionList(): array
    {
        return collect(preg_split('/[|\r\n]+/', (string) $this->match_expressions) ?: [])
            ->map(fn (string $item): string => trim($item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Ainda precisa de um clique para cada conversa?
     *
     * Perfil novo não sai sozinho. O primeiro dia é onde se descobre que a
     * expressão pegou o que não devia e que o texto de abertura soa errado, e
     * descobrir isso depois de duzentas conversas é caro demais.
     *
     * O teto zero desliga a exigência — é a saída para quem já homologou o
     * texto por fora e não quer repetir o rito.
     */
    public function needsHumanApproval(): bool
    {
        if ($this->homologation_threshold <= 0 || $this->homologated_at !== null) {
            return false;
        }

        return $this->approved_starts_count < $this->homologation_threshold;
    }

    public function isRunnable(): bool
    {
        return $this->status->isRunnable();
    }
}
