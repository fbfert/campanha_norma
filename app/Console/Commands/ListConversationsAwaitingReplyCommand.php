<?php

namespace App\Console\Commands;

use App\Enums\ConversationMessageDirection;
use App\Models\Conversation;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use Illuminate\Console\Command;

/**
 * Conversas em que a pessoa falou por último e ninguém respondeu.
 *
 * A caixa mostra "aguardando operador" para qualquer conversa com mensagem
 * recebida, inclusive as que a automação vai responder daqui a um minuto. O que
 * interessa e outra coisa: onde a última palavra e da pessoa, ha tempo
 * suficiente para não ser fila, e por que ninguém respondeu.
 */
class ListConversationsAwaitingReplyCommand extends Command
{
    protected $signature = 'conversations:awaiting-reply
        {--minutes=15 : Idade mínima da última mensagem recebida, para não listar o que ainda esta na fila}
        {--limit=50 : Teto de conversas listadas}';

    protected $description = 'Lista conversas em que a pessoa falou por último e não houve resposta.';

    public function handle(): int
    {
        $limite = now()->subMinutes(max(0, (int) $this->option('minutes')));

        $conversas = Conversation::query()
            ->whereNotNull('last_incoming_message_at')
            ->where('last_incoming_message_at', '<=', $limite)
            ->orderByDesc('last_incoming_message_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->filter(fn (Conversation $conversa): bool => $this->awaitingReply($conversa));

        if ($conversas->isEmpty()) {
            $this->info('Nenhuma conversa esperando resposta.');

            return self::SUCCESS;
        }

        $this->table(
            ['Conversa', 'Contato', 'Última recebida', 'Situação do fluxo', 'Por que parou'],
            $conversas->map(fn (Conversation $conversa): array => [
                $conversa->id,
                $conversa->contact?->name ?? 'sem contato',
                $conversa->last_incoming_message_at?->format('d/m H:i') ?? '-',
                $this->flowLabel($conversa),
                $this->reason($conversa),
            ])->all(),
        );

        $this->line('');
        $this->info($conversas->count().' conversa(s) com a última palavra da pessoa.');

        return self::SUCCESS;
    }

    /**
     * A última mensagem da conversa e da pessoa?
     *
     * Compara identificadores, e não datas: mensagem sincronizada do celular
     * chega com a data original e desordenaria a comparação por tempo.
     */
    private function awaitingReply(Conversation $conversa): bool
    {
        $ultima = ConversationMessage::query()
            ->where('conversation_id', $conversa->id)
            ->orderByDesc('id')
            ->first();

        return $ultima !== null && $ultima->direction === ConversationMessageDirection::Incoming;
    }

    private function state(Conversation $conversa): ?ConversationFlowState
    {
        return ConversationFlowState::query()->with('flow')->where('conversation_id', $conversa->id)->first();
    }

    private function flowLabel(Conversation $conversa): string
    {
        $state = $this->state($conversa);

        return $state === null
            ? 'sem fluxo'
            : $state->flow?->name.' / '.$state->current_stage->value;
    }

    /**
     * O motivo em linguagem de quem opera, e não de quem escreveu o código.
     */
    private function reason(Conversation $conversa): string
    {
        $state = $this->state($conversa);

        if ($state === null) {
            return 'Conversa sem pesquisa vinculada: a automação não atende.';
        }

        if ($state->is_paused) {
            return 'Automação pausada nesta conversa.';
        }

        if ($state->current_stage->isTerminal()) {
            return 'Pesquisa encerrada; o que vier depois não e respondido.';
        }

        if ($state->needs_human_review) {
            return 'Encaminhada para atendimento humano.';
        }

        return 'Aguardando a automação; se persistir, verificar a fila.';
    }
}
