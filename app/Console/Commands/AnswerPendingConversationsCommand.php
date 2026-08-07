<?php

namespace App\Console\Commands;

use App\Enums\ContactStatus;
use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageStatus;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\ConversationAutomation\PendingReplyResolver;
use App\Services\SystemSettingService;
use Illuminate\Console\Command;

/**
 * Rede de segurança: ninguém fica sem resposta.
 *
 * A automação tem várias saídas legítimas que terminam em silêncio — pesquisa
 * encerrada, conversa encaminhada para gente, job perdido, fluxo pausado. Cada
 * uma faz sentido isolada, e o efeito somado é o mesmo para quem escreveu:
 * respondeu e não recebeu nada.
 *
 * Este comando fecha esse buraco por baixo. Ele não substitui a automação: age
 * só onde ela já teve tempo e não agiu. A decisão de cada conversa fica em
 * `PendingReplyResolver`, que tenta responder de verdade antes de agradecer.
 */
class AnswerPendingConversationsCommand extends Command
{
    protected $signature = 'conversations:answer-pending
        {--minutes= : Minutos de silêncio tolerados; padrão vem da configuração}
        {--dry-run : Mostra o que faria, sem enviar nada}';

    protected $description = 'Garante resposta a quem falou por último e ficou sem retorno.';

    public function handle(SystemSettingService $settings, PendingReplyResolver $resolver): int
    {
        $minutos = (int) ($this->option('minutes') ?: $settings->get('conversation_automation.unanswered_after_minutes', 15));
        $seco = (bool) $this->option('dry-run');

        $limite = now()->subMinutes(max(1, $minutos));
        $contagem = [];

        foreach ($this->awaitingReply($limite) as $conversa) {
            if (! $this->canReply($conversa)) {
                continue;
            }

            $ultima = $this->lastIncoming($conversa);

            if (! $ultima) {
                continue;
            }

            $resultado = $resolver->resolve($conversa, $ultima, $seco);
            $contagem[$resultado['outcome']] = ($contagem[$resultado['outcome']] ?? 0) + 1;

            $this->line(sprintf(
                'conversa %-5s %s%s',
                $conversa->id,
                $resultado['outcome'],
                $resultado['reason'] ? ' ('.$resultado['reason'].')' : '',
            ));
        }

        ksort($contagem);

        foreach ($contagem as $desfecho => $total) {
            $this->info("{$desfecho}: {$total}");
        }

        if ($contagem === []) {
            $this->info('Nenhuma conversa esperando resposta.');
        }

        if ($seco) {
            $this->comment('Simulação: nada foi enviado.');
        }

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Conversation> */
    private function awaitingReply(\Carbon\CarbonInterface $limite)
    {
        return Conversation::query()
            ->with('contact')
            ->whereNotNull('last_incoming_message_at')
            ->where('last_incoming_message_at', '<=', $limite)
            ->orderByDesc('last_incoming_message_at')
            ->limit(200)
            ->get()
            ->filter(function (Conversation $conversa): bool {
                $ultima = ConversationMessage::query()
                    ->where('conversation_id', $conversa->id)
                    // Saída que falhou não é resposta, e contava como tal aqui
                    // também: bastava uma tentativa recusada para a conversa
                    // sair desta lista e nunca mais ser tentada.
                    ->where(fn ($query) => $query
                        ->where('direction', ConversationMessageDirection::Incoming)
                        ->orWhereNotIn('status', [
                            ConversationMessageStatus::Failed,
                            ConversationMessageStatus::Cancelled,
                        ]))
                    ->orderByDesc('id')
                    ->first();

                return $ultima !== null && $ultima->direction === ConversationMessageDirection::Incoming;
            });
    }

    private function lastIncoming(Conversation $conversa): ?ConversationMessage
    {
        return ConversationMessage::query()
            ->where('conversation_id', $conversa->id)
            ->where('direction', ConversationMessageDirection::Incoming)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Conversa sem contato identificado, bloqueado ou marcado como não contatar
     * não recebe nada — nem resposta, nem aviso.
     */
    private function canReply(Conversation $conversa): bool
    {
        $contato = $conversa->contact;

        if (! $contato || $contato->do_not_contact) {
            return false;
        }

        return $contato->status !== ContactStatus::Blocked;
    }
}
