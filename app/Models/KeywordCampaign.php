<?php

namespace App\Models;

use App\Enums\KeywordCampaignStatus;
use App\Enums\KeywordParticipationEligibility;
use App\Enums\KeywordParticipationStatus;
use App\Services\KeywordCampaigns\KeywordCampaignTrigger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Campanha por palavra-chave.
 *
 * Quem escreve uma das palavras dentro da vigência vira participação. O resto
 * da campanha — conferência, congelamento, sorteio, cupom — trabalha sobre essa
 * lista.
 */
class KeywordCampaign extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'status',
        'conversation_flow_id',
        'keywords',
        'starts_at',
        'ends_at',
        'participant_limit',
        'hourly_alert_threshold',
        'hourly_alert_raised_at',
        'confirmation_text',
        'already_enrolled_text',
        'survey_invite_text',
        'out_of_window_text',
        'coupon_text',
        'frozen_at',
        'frozen_by',
        'frozen_list_hash',
        'frozen_list_count',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => KeywordCampaignStatus::class,
            'keywords' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'participant_limit' => 'integer',
            'hourly_alert_threshold' => 'integer',
            'hourly_alert_raised_at' => 'datetime',
            'frozen_at' => 'datetime',
            'frozen_list_count' => 'integer',
        ];
    }

    /**
     * Gravar uma campanha derruba o cache de vigentes.
     *
     * Sem isto, ligar a campanha na tela não a ligaria de verdade pelo tempo do
     * cache — e o primeiro relato seria "mandei a palavra e não aconteceu
     * nada", que é impossível de reproduzir depois que o cache expira.
     */
    protected static function booted(): void
    {
        $limpar = fn (): mixed => KeywordCampaignTrigger::esquecerCache();

        static::saved($limpar);
        static::deleted($limpar);
        static::restored($limpar);
    }

    public function participations(): HasMany
    {
        return $this->hasMany(KeywordCampaignParticipation::class);
    }

    public function draws(): HasMany
    {
        return $this->hasMany(KeywordCampaignDraw::class);
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(KeywordCampaignCoupon::class);
    }

    public function conversationFlow(): BelongsTo
    {
        return $this->belongsTo(ConversationFlow::class, 'conversation_flow_id');
    }

    /**
     * A campanha abre pesquisa depois de confirmar a inscrição?
     *
     * Sem fluxo, ela só sorteia. É o padrão: perguntar alguma coisa a quem
     * escreveu uma palavra para concorrer a um prêmio é um segundo pedido, e
     * um segundo pedido precisa ser uma decisão de quem monta a campanha.
     */
    public function abrePesquisa(): bool
    {
        return $this->conversation_flow_id !== null;
    }

    /**
     * O convite da pesquisa que vai emendado à confirmação.
     *
     * Cai para o texto de apresentação do fluxo, que é onde o pedido de
     * permissão já mora — e é o mesmo texto que o motor da 9A espera ter
     * enviado quando coloca a conversa em `waiting_permission`.
     */
    public function conviteDePesquisa(): ?string
    {
        $texto = trim((string) ($this->survey_invite_text ?: $this->conversationFlow?->presentation_text));

        return $texto === '' ? null : $texto;
    }

    public function freezer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'frozen_by');
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
     * Campanhas que aceitam inscrição agora.
     *
     * Este escopo roda em toda mensagem recebida, então ele é o caminho quente
     * da etapa inteira. As bordas são inclusivas: quem escreve no segundo exato
     * em que a campanha abre está dentro, porque o contrário produziria a
     * reclamação impossível de responder de quem mandou "na hora certa" e não
     * entrou.
     */
    public function scopeVigente(Builder $query): Builder
    {
        return $query
            ->where('status', KeywordCampaignStatus::Ativa)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now());
    }

    /**
     * Campanhas que o gatilho testa contra a mensagem.
     *
     * É mais largo que `vigente` de propósito: uma campanha ativa cujo período
     * já passou continua reconhecendo a própria palavra, para poder responder
     * que acabou. Sem isso, quem viu o cartaz uma semana depois escreve e não
     * recebe nada — e conclui que a inscrição deu certo.
     *
     * `encerrada` e `congelada` ficam de fora: são o desligamento deliberado, e
     * é assim que o operador manda a campanha calar de vez.
     */
    public function scopeAvaliavel(Builder $query): Builder
    {
        return $query->where('status', KeywordCampaignStatus::Ativa);
    }

    /**
     * Inscritos que contam para o limite e para a lista.
     *
     * Em revisão e invalidada ficam de fora: a primeira ainda não é uma
     * inscrição resolvida, e a segunda deixou de ser uma.
     */
    public function validParticipations(): HasMany
    {
        return $this->participations()->whereIn('status', [
            KeywordParticipationStatus::Valida,
            KeywordParticipationStatus::SemNome,
        ]);
    }

    public function pendentesDeConferencia(): HasMany
    {
        return $this->validParticipations()
            ->where('eligibility', KeywordParticipationEligibility::NaoVerificada);
    }

    /**
     * Já bateu o limite de participantes?
     *
     * Limite nulo é sem limite. Conta apenas participação válida, porque uma
     * invalidada não deveria ocupar vaga de ninguém.
     */
    public function atingiuLimite(): bool
    {
        if ($this->participant_limit === null) {
            return false;
        }

        return $this->validParticipations()->count() >= $this->participant_limit;
    }

    public function estaVigente(): bool
    {
        return $this->status === KeywordCampaignStatus::Ativa
            && $this->starts_at !== null
            && $this->ends_at !== null
            && $this->starts_at->lessThanOrEqualTo(now())
            && $this->ends_at->greaterThanOrEqualTo(now());
    }

    public function estaCongelada(): bool
    {
        return $this->frozen_at !== null;
    }

    /**
     * As palavras já saem normalizadas do banco: a normalização acontece na
     * gravação, para o caminho quente não pagar por ela a cada mensagem.
     *
     * @return list<string>
     */
    public function keywordList(): array
    {
        return collect($this->keywords ?? [])
            ->map(fn ($item): string => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
