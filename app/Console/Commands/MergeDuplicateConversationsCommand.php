<?php

namespace App\Console\Commands;

use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationEventService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Junta conversas duplicadas do mesmo contato.
 *
 * Duas portas criam conversa com chaves de identidade diferentes, e o histórico
 * de uma pessoa acabou repartido em duas telas. Este comando devolve tudo para
 * uma conversa so.
 *
 * Ele não apaga nada: move os registros, arquiva a conversa esvaziada e deixa
 * no histórico de ambas o registro de para onde as mensagens foram. Junção mal
 * feita e pior que duplicata, porque some com a origem.
 */
class MergeDuplicateConversationsCommand extends Command
{
    protected $signature = 'conversations:merge-duplicates
        {--contact= : Junta apenas as conversas deste contato}
        {--apply : Executa de verdade; sem isto apenas simula}';

    protected $description = 'Junta conversas duplicadas do mesmo contato em uma so.';

    /**
     * Tabelas que apontam para a conversa e acompanham a junção.
     *
     * `conversation_flow_states` fica de fora: ha no máximo um estado por
     * conversa, e mover um por cima do outro criaria uma pesquisa com dois
     * históricos. Ele e tratado a parte.
     */
    private const RELATED_TABLES = [
        'conversation_messages',
        'conversation_events',
        'conversation_notes',
        'conversation_assignments',
        'conversation_conversation_tag',
        'conversation_message_classifications',
        'conversation_insights',
        'conversation_reply_suggestions',
        'conversation_flow_transitions',
        'conversation_flow_question_usages',
        'knowledge_retrievals',
        'ai_runs',
    ];

    public function __construct(private readonly ConversationEventService $events)
    {
        parent::__construct();
    }

    public function handle(AuditLogger $audit): int
    {
        $aplicar = (bool) $this->option('apply');
        $grupos = $this->duplicates();

        if ($grupos->isEmpty()) {
            $this->info('Nenhum contato com conversa duplicada.');

            return self::SUCCESS;
        }

        $juntadas = 0;

        foreach ($grupos as $contactId => $conversas) {
            $principal = $this->principal($conversas);
            $secundarias = $conversas->reject(fn (Conversation $c): bool => $c->id === $principal->id);

            $this->line('');
            $this->info("Contato {$contactId} — {$principal->contact?->name}");
            $this->line("  principal: conversa {$principal->id}".($principal->external_chat_id ? ' (com chat id)' : '').', '.$this->messageCount($principal).' mensagens');

            foreach ($secundarias as $secundaria) {
                $total = $this->messageCount($secundaria);
                $temFluxo = DB::table('conversation_flow_states')->where('conversation_id', $secundaria->id)->exists();
                $principalTemFluxo = DB::table('conversation_flow_states')->where('conversation_id', $principal->id)->exists();

                $this->line("  mover: conversa {$secundaria->id} com {$total} mensagens".($temFluxo ? ' e estado de pesquisa' : ''));

                if ($temFluxo && $principalTemFluxo) {
                    $this->warn("    as duas tem estado de pesquisa; o estado da {$secundaria->id} fica onde esta, para não perder histórico.");
                }

                // Dois chat ids diferentes são dois chats de verdade no
                // WhatsApp. Juntar quebraria a identidade que a sincronização
                // usa para reencontrar cada um.
                if ($secundaria->external_chat_id && $principal->external_chat_id) {
                    $this->warn('    as duas tem chat id próprio: são conversas distintas no WhatsApp e não serão juntadas.');

                    continue;
                }

                if ($secundaria->external_chat_id) {
                    $this->line("    o chat id {$secundaria->external_chat_id} passa para a conversa {$principal->id}.");
                }

                if (! $aplicar) {
                    continue;
                }

                $this->merge($principal, $secundaria, $temFluxo && ! $principalTemFluxo, $audit);
                $juntadas++;
            }
        }

        $this->line('');

        $this->info($aplicar
            ? "{$juntadas} conversa(s) juntada(s)."
            : 'Simulação. Use --apply para executar.');

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, Conversation>> */
    private function duplicates()
    {
        return Conversation::query()
            ->with('contact')
            ->whereNotNull('contact_id')
            ->when($this->option('contact'), fn ($query) => $query->where('contact_id', (int) $this->option('contact')))
            ->get()
            ->groupBy('contact_id')
            ->filter(fn ($conversas): bool => $conversas->count() > 1);
    }

    /**
     * A conversa que fica: a que tem mais história.
     *
     * O primeiro critério que tentei foi o `external_chat_id`, por ser o que a
     * sincronização reencontra. A simulação mostrou o estrago: em três dos
     * cinco casos a conversa com chat id era a menor, e a junção arquivaria
     * justamente a conversa onde a pesquisa aconteceu — 61 mensagens indo parar
     * dentro de uma de 12.
     *
     * O chat id acompanha a mudança, então nada se perde: ele e movido para a
     * conversa que fica. Empate se resolve pela mais antiga.
     */
    private function principal($conversas): Conversation
    {
        return $conversas
            ->sortBy(fn (Conversation $c): array => [-$this->messageCount($c), $c->id])
            ->first();
    }

    private function messageCount(Conversation $conversa): int
    {
        return ConversationMessage::query()->where('conversation_id', $conversa->id)->count();
    }

    private function merge(Conversation $principal, Conversation $secundaria, bool $moverFluxo, AuditLogger $audit): void
    {
        DB::transaction(function () use ($principal, $secundaria, $moverFluxo): void {
            foreach (self::RELATED_TABLES as $tabela) {
                DB::table($tabela)->where('conversation_id', $secundaria->id)->update(['conversation_id' => $principal->id]);
            }

            if ($moverFluxo) {
                DB::table('conversation_flow_states')->where('conversation_id', $secundaria->id)->update(['conversation_id' => $principal->id]);
            }

            $chatId = $secundaria->external_chat_id;

            // A chave e única por provedor: precisa sair de uma antes de entrar
            // na outra, na mesma transação.
            $secundaria->forceFill([
                'external_chat_id' => null,
                'status' => ConversationStatus::Archived,
                'is_archived' => true,
                'archived_at' => now(),
                'unread_count' => 0,
            ])->save();

            if ($chatId && ! $principal->external_chat_id) {
                $principal->forceFill(['external_chat_id' => $chatId, 'provider' => 'web'])->save();
            }

            $this->recount($principal);
        });

        // O rastro precisa ficar na tela, e não so na auditoria. Os eventos
        // antigos acompanharam as mensagens, então a conversa esvaziada ficaria
        // completamente em branco: quem abrisse não teria como saber que ali
        // houve conversa nem para onde ela foi.
        $this->events->record(
            $secundaria,
            'conversation_merged_away',
            "Histórico movido para a conversa {$principal->id}.",
            null,
            null,
            ['destino' => $principal->id],
        );

        $this->events->record(
            $principal,
            'conversation_merged_in',
            "Histórico da conversa {$secundaria->id} incorporado aqui.",
            null,
            null,
            ['origem' => $secundaria->id],
        );

        // O registro fica nas duas: quem abrir a esvaziada entende para onde o
        // histórico foi, e quem abrir a principal sabe de onde ele veio.
        $audit->log(
            'conversation.merged',
            'Conversas duplicadas juntadas.',
            $principal,
            ['conversation_id' => $secundaria->id],
            ['conversation_id' => $principal->id, 'origem' => $secundaria->id],
        );
    }

    /**
     * Recalcula os marcadores da conversa que ficou, a partir das mensagens que
     * ela passou a ter.
     */
    private function recount(Conversation $principal): void
    {
        $mensagens = ConversationMessage::query()->where('conversation_id', $principal->id);

        $ultima = (clone $mensagens)->orderByDesc('id')->first();

        $principal->forceFill([
            'last_message_at' => $ultima?->created_at,
            'last_message_direction' => $ultima?->direction,
            'last_incoming_message_at' => (clone $mensagens)->where('direction', ConversationMessageDirection::Incoming)->max('created_at'),
            'last_outgoing_message_at' => (clone $mensagens)->where('direction', ConversationMessageDirection::Outgoing)->max('created_at'),
            'is_archived' => false,
            'archived_at' => null,
        ])->save();
    }
}
