<?php

namespace App\Console\Commands;

use App\Enums\ConversationMessageDirection;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Conversations\ConversationSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recolhe avisos de protocolo que entraram como se fossem mensagem de pessoa.
 *
 * `e2e_notification`, `notification_template`, `revoked` e companhia são gerados
 * pelo próprio WhatsApp — o "suas mensagens são protegidas com criptografia de
 * ponta a ponta", emitido quando a chave do contato muda. Ninguém escreveu nada.
 *
 * Entravam como mensagem recebida de corpo vazio, e o efeito era duplo: a
 * automação lia aquilo como resposta, não entendia e encaminhava a conversa para
 * atendimento humano; e conversas nasciam só disso, sem contato identificado,
 * indo parar na fila de "Aguardando operador". Quem abria encontrava uma tela
 * vazia.
 *
 * `ConversationSyncService::PROTOCOL_TYPES` fechou a porta em 03/08/2026, nos
 * dois caminhos de entrada. Este comando limpa o que entrou antes disso.
 *
 * Conversa que fica sem nenhuma mensagem é removida (soft delete): ela não
 * representa contato nenhum, e mantê-la só suja a fila. Conversa que tinha
 * conversa de verdade perde apenas a linha vazia do meio.
 */
class PruneProtocolNoticesCommand extends Command
{
    protected $signature = 'conversations:prune-protocol-notices
        {--aplicar : Grava. Sem esta opção o comando apenas mostra o que faria}';

    protected $description = 'Recolhe avisos do WhatsApp que entraram como mensagem recebida.';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $tipos = ConversationSyncService::PROTOCOL_TYPES;

        $avisos = ConversationMessage::query()->whereIn('message_type', $tipos);
        $total = (clone $avisos)->count();

        if ($total === 0) {
            $this->info('Nada a recolher.');

            return self::SUCCESS;
        }

        $conversas = (clone $avisos)->distinct()->pluck('conversation_id');
        $esvaziadas = [];

        foreach ($conversas as $id) {
            $conversa = Conversation::find($id);

            if (! $conversa) {
                continue;
            }

            $todas = ConversationMessage::query()->where('conversation_id', $id)->count();
            $ruido = ConversationMessage::query()->where('conversation_id', $id)->whereIn('message_type', $tipos)->count();
            $sobra = $todas - $ruido;

            $this->line(sprintf(
                '  conversa %-7s %-22s %d aviso(s), restam %d mensagem(ns)%s',
                $id,
                mb_substr((string) ($conversa->contact?->name ?? 'sem contato'), 0, 22),
                $ruido,
                $sobra,
                $sobra === 0 ? '  <- conversa removida' : '',
            ));

            if ($sobra === 0) {
                $esvaziadas[] = $id;
            }

            if (! $aplicar) {
                continue;
            }

            DB::transaction(function () use ($id, $tipos, $sobra, $conversa): void {
                ConversationMessage::query()
                    ->where('conversation_id', $id)
                    ->whereIn('message_type', $tipos)
                    ->delete();

                if ($sobra === 0) {
                    $conversa->delete();

                    return;
                }

                $this->refreshMarkers($conversa);
            });
        }

        $this->newLine();
        $this->info($total.' aviso(s) '.($aplicar ? 'recolhido(s).' : 'seriam recolhidos.'));
        $this->info(count($esvaziadas).' conversa(s) '.($aplicar ? 'removida(s)' : 'seriam removidas').': '.(implode(', ', $esvaziadas) ?: '-'));

        if (! $aplicar) {
            $this->warn('Nada foi gravado. Repita com --aplicar.');
        }

        return self::SUCCESS;
    }

    /**
     * Recalcula os marcadores da conversa a partir do que sobrou.
     *
     * A conversa guarda a data e a direção da última mensagem para ordenar a
     * listagem sem varrer tudo. Apagar a mensagem que esses campos apontam
     * deixaria a conversa ordenada por um registro que não existe mais — e
     * três conversas apontavam exatamente para o aviso que este comando apaga.
     */
    private function refreshMarkers(Conversation $conversa): void
    {
        $ultima = ConversationMessage::query()
            ->where('conversation_id', $conversa->id)
            ->orderByDesc('id')
            ->first();

        $momento = fn (?ConversationMessage $m) => $m?->received_at ?? $m?->sent_at ?? $m?->created_at;

        $ultimaEntrada = ConversationMessage::query()
            ->where('conversation_id', $conversa->id)
            ->where('direction', ConversationMessageDirection::Incoming)
            ->orderByDesc('id')
            ->first();

        $ultimaSaida = ConversationMessage::query()
            ->where('conversation_id', $conversa->id)
            ->where('direction', ConversationMessageDirection::Outgoing)
            ->orderByDesc('id')
            ->first();

        $conversa->forceFill([
            'last_message_direction' => $ultima?->direction,
            'last_message_at' => $momento($ultima),
            'last_incoming_message_at' => $momento($ultimaEntrada),
            'last_outgoing_message_at' => $momento($ultimaSaida),
        ])->save();
    }
}
