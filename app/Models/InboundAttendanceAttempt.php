<?php

namespace App\Models;

use App\Enums\InboundAttendanceOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de cada decisão do atendimento de entrada, inclusive a de não agir.
 *
 * Sem isto, "por que essa conversa não foi atendida" só se responde lendo log
 * de servidor. O motivo aparece ao lado da conversa na fila, e é por esta
 * tabela que o teto diário sabe quanto já saiu hoje.
 */
class InboundAttendanceAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'conversation_message_id',
        'inbound_attendance_profile_id',
        'outcome',
        'reason',
        'started_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'outcome' => InboundAttendanceOutcome::class,
            'metadata' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'conversation_message_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(InboundAttendanceProfile::class, 'inbound_attendance_profile_id');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    /**
     * Motivo em português, para a fila.
     *
     * O código do motivo é o que o teste compara e o que a auditoria guarda;
     * esta função existe só para a tela, e um motivo desconhecido devolve o
     * próprio código em vez de sumir.
     */
    public function reasonLabel(): string
    {
        return match ($this->reason) {
            'atendimento_desligado' => 'Atendimento de entrada desligado',
            'automacao_desligada' => 'Automação conversacional desligada',
            'envio_automatico_desligado' => 'Envio automático desligado',
            'sem_perfil' => 'Nenhum perfil ativo, nem de fallback',
            'perfil_inativo' => 'Perfil pausado ou em rascunho',
            'perfil_sem_fluxo' => 'Perfil sem fluxo conversacional vinculado',
            'aguardando_homologacao' => 'Perfil novo: aguardando sua aprovação',
            'fora_da_janela_de_horario' => 'Fora da janela de horário do perfil',
            'teto_diario_do_perfil' => 'Teto diário do perfil atingido',
            'teto_diario_global' => 'Teto diário geral atingido',
            'sem_conexao' => 'Sessão do WhatsApp fora do ar',
            'contato_nao_contatar' => 'Contato marcado como não contatar',
            'contato_inativo' => 'Contato inativo',
            'telefone_invalido' => 'Telefone da conversa inválido',
            'conversa_ja_tem_fluxo' => 'Conversa já está em um fluxo',
            'mensagem_ignorada' => 'Mensagem de robô ou operadora',
            'ignorada_manualmente' => 'Ignorada por decisão de quem atende',
            'numero_interno' => 'Número da própria equipe',
            'mensagem_antiga' => 'Mensagem antiga demais para abrir conversa',
            'resposta_ia_indisponivel' => 'IA não produziu resposta confiável',
            'sem_texto_de_abertura' => 'Perfil sem texto de apresentação',
            'envio_recusado' => 'Envio recusado pela porta da automação',
            default => $this->reason ?? '—',
        };
    }
}
