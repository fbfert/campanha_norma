<?php

namespace App\Models;

use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageOrigin;
use App\Enums\ConversationMessageStatus;
use App\Enums\TranscriptionStatus;
use App\Support\MantemChaveDeLixeira;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConversationMessage extends Model
{
    use HasFactory, MantemChaveDeLixeira, SoftDeletes;

    /** O tipo com que uma reação é gravada, nos dois provedores. */
    public const TYPE_REACTION = 'reaction';

    protected $fillable = [
        'conversation_id',
        'contact_id',
        'message_batch_recipient_id',
        'direction',
        'message_type',
        'provider',
        'origin',
        'generated_by_ai',
        'ai_run_id',
        'ai_prompt_version',
        'ai_confidence',
        'approved_by',
        'approved_at',
        'automation_state_transition_id',
        'external_chat_id',
        'external_message_id',
        'event_id',
        'request_id',
        'sender_phone_snapshot',
        'recipient_phone_snapshot',
        'sender_name_snapshot',
        'body',
        'has_media',
        'media_metadata',
        'quoted_message_id',
        'status',
        'sent_at',
        'received_at',
        'read_at',
        'failed_at',
        'error_code',
        'error_message',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'direction' => ConversationMessageDirection::class,
            'origin' => ConversationMessageOrigin::class,
            'generated_by_ai' => 'boolean',
            'ai_confidence' => 'float',
            'approved_at' => 'datetime',
            'status' => ConversationMessageStatus::class,
            'has_media' => 'boolean',
            'media_metadata' => 'array',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Esta mensagem é uma reação?
     *
     * `message_type` é string livre no banco, sem enum: o vocabulário é do
     * WhatsApp, não nosso, e fechá-lo já custou toda nota de voz uma vez. O
     * literal fica concentrado aqui para que o resto do sistema pergunte em vez
     * de comparar.
     */
    public function isReaction(): bool
    {
        return $this->message_type === self::TYPE_REACTION;
    }

    /**
     * A mensagem em que esta reação foi feita.
     *
     * `quoted_message_id` guarda o identificador do provedor, e não a chave
     * estrangeira: a mensagem reagida pode ser mais antiga que a sincronização
     * e simplesmente não existir aqui. Nesse caso isto devolve `null`, e quem
     * chama trata como reação sem alvo conhecido — que é o mesmo que reação que
     * não decide nada.
     */
    public function reactedTo(): ?self
    {
        $alvo = $this->reacted;

        return $alvo && $alvo->conversation_id === $this->conversation_id ? $alvo : null;
    }

    /**
     * A linha única que descreve esta mensagem numa lista.
     *
     * A prévia da caixa de entrada mostrava `body` cru, e para uma reação isso
     * era um emoji sozinho no meio da fila — indistinguível de alguém que
     * mandou só um emoji, e sem dizer que houve resposta a alguma coisa. Quem
     * varre a fila decide pela prévia se abre a conversa.
     */
    public function previewText(): string
    {
        if ($this->isReaction()) {
            return 'Reagiu com '.trim((string) $this->body);
        }

        if (filled($this->body)) {
            return (string) $this->body;
        }

        return $this->has_media ? '[midia]' : 'Sem conteúdo';
    }

    /**
     * A última mensagem nossa anterior a esta.
     *
     * É assim que "a mensagem que perguntou" fica identificável sem depender de
     * onde a pergunta nasceu: fluxo que começa por lote tem a pergunta na
     * mensagem do lote, fluxo que continua sozinho tem a pergunta na resposta
     * automática, e campanha tem a pergunta na confirmação. Todas são, no
     * momento em que a resposta chega, a última coisa que dissemos.
     *
     * Só conta o que existia antes desta mensagem: se enquanto a resposta vinha
     * nós enviamos outra coisa, a pergunta respondida continua sendo a de antes.
     */
    public function ultimaMensagemNossaAntes(): ?self
    {
        return static::query()
            ->where('conversation_id', $this->conversation_id)
            ->where('direction', ConversationMessageDirection::Outgoing)
            ->where('id', '<', $this->id)
            ->latest('id')
            ->first();
    }

    /**
     * A relação crua por identificador do provedor.
     *
     * Existe para a tela poder carregar tudo de uma vez em vez de consultar o
     * banco uma vez por mensagem. Quem decide alguma coisa usa `reactedTo()`,
     * que ainda confere se o alvo é da mesma conversa: identificador de
     * provedor é único na prática, mas "na prática" não é garantia de banco.
     */
    public function reacted(): BelongsTo
    {
        return $this->belongsTo(self::class, 'quoted_message_id', 'external_message_id');
    }

    public function transcriptions(): HasMany
    {
        return $this->hasMany(MessageTranscription::class)->latest('id');
    }

    /**
     * Arquivo desta mídia, se alguém já precisou dele.
     *
     * Carregar junto com as mensagens é o que permite a tela saber, sem ir ao
     * provedor, que uma mídia não pôde ser recuperada — e dizer isso, em vez de
     * apontar um `<img>` para um arquivo que não existe.
     */
    public function medium(): HasOne
    {
        return $this->hasOne(ConversationMessageMedium::class, 'conversation_message_id');
    }

    /**
     * Transcrição que vale para esta mensagem.
     */
    public function transcription(): ?MessageTranscription
    {
        // Com a relação já carregada, procurar na coleção em vez de consultar:
        // a linha do tempo chama isto uma vez por mensagem, e sem esta guarda
        // são cinquenta consultas para desenhar uma conversa.
        if ($this->relationLoaded('transcriptions')) {
            return $this->transcriptions->firstWhere('status', TranscriptionStatus::Succeeded);
        }

        return $this->transcriptions()
            ->where('status', TranscriptionStatus::Succeeded)
            ->first();
    }

    /**
     * O texto que representa esta mensagem para quem precisa lê-la.
     *
     * Áudio e imagem chegam com corpo vazio. Onde ha transcrição ou descrição
     * aproveitável, ela ocupa esse lugar — sem sobrescrever o corpo, que
     * continua sendo o registro do que de fato chegou.
     *
     * Este método existia e não era chamado por ninguém: o classificador, os
     * construtores de contexto e o gerador de resposta liam `body` direto. O
     * efeito era um áudio transcrito com sucesso chegar ao motor como texto
     * vazio, virar `ambiguous` e ir para atendimento humano — a transcrição era
     * paga, gravada e ignorada. Como a transcrição estava desligada em
     * produção, ninguém percebeu.
     */
    public function readableText(): string
    {
        if (filled($this->body)) {
            return (string) $this->body;
        }

        return (string) ($this->transcription()?->text ?? '');
    }

    /**
     * Mensagens que têm texto para ler, incluindo o que a máquina extraiu.
     *
     * As consultas de histórico filtravam `body` não nulo, o que descartava
     * silenciosamente todo áudio e toda imagem do contexto mandado ao modelo.
     */
    public function scopeWithReadableText(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->whereNotNull('body')
                ->orWhereHas('transcriptions', fn ($sub) => $sub->where('status', TranscriptionStatus::Succeeded));
        });
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(MessageBatchRecipient::class, 'message_batch_recipient_id');
    }

    public function classifications(): HasMany
    {
        return $this->hasMany(ConversationMessageClassification::class)->latest('id');
    }

    public function insights(): HasMany
    {
        return $this->hasMany(ConversationInsight::class, 'source_message_id')->latest('extraction_version');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function suggestion(): HasOne
    {
        return $this->hasOne(ConversationReplySuggestion::class, 'sent_message_id');
    }
}
