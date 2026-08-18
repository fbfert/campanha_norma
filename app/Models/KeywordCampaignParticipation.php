<?php

namespace App\Models;

use App\Enums\KeywordParticipationEligibility;
use App\Enums\KeywordParticipationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A inscrição de uma pessoa numa campanha.
 *
 * É projeção da mensagem que a originou, e não efeito colateral dela: por isso
 * `conversation_message_id` é obrigatório e por isso a lista inteira pode ser
 * reconstruída pelo comando de reprocessamento quando um job morre no meio de
 * uma divulgação.
 */
class KeywordCampaignParticipation extends Model
{
    use HasFactory;

    protected $fillable = [
        'keyword_campaign_id',
        'contact_id',
        'conversation_message_id',
        'matched_keyword',
        'captured_name',
        'reviewed_name',
        'name_reviewed_by',
        'name_reviewed_at',
        'status',
        'eligibility',
        'reviewed_by',
        'reviewed_at',
        'invalidated_by',
        'invalidated_at',
        'invalidation_reason',
    ];

    protected function casts(): array
    {
        return [
            'status' => KeywordParticipationStatus::class,
            'eligibility' => KeywordParticipationEligibility::class,
            'name_reviewed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(KeywordCampaign::class, 'keyword_campaign_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'conversation_message_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function invalidator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invalidated_by');
    }

    public function scopePorSituacao(Builder $query, KeywordParticipationStatus $situacao): Builder
    {
        return $query->where('status', $situacao);
    }

    /**
     * Inscrições que ainda impedem o congelamento da lista.
     *
     * Invalidada não conta: quem já saiu da lista não precisa de conferência
     * para sair de novo.
     */
    public function scopePendentesDeConferencia(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [KeywordParticipationStatus::Valida, KeywordParticipationStatus::SemNome])
            ->where('eligibility', KeywordParticipationEligibility::NaoVerificada);
    }

    /**
     * Entra na lista congelada.
     */
    public function scopeElegivelParaSorteio(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [KeywordParticipationStatus::Valida, KeywordParticipationStatus::SemNome])
            ->where('eligibility', KeywordParticipationEligibility::AlunoConfirmado);
    }

    /**
     * O nome que a tela mostra.
     *
     * A correção humana vence o que o provedor informou, mas o original
     * continua gravado: é o que permite responder de onde veio o nome errado.
     */
    public function displayName(): ?string
    {
        return $this->reviewed_name ?: $this->captured_name;
    }
}
