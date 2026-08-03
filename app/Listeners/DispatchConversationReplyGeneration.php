<?php

namespace App\Listeners;

use App\Enums\ConversationFlowStage;
use App\Events\ConversationMessageEvaluated;
use App\Jobs\GenerateConversationReplyJob;
use App\Services\ResponseGeneration\ResponseModeResolver;
use App\Services\SystemSettingService;

/**
 * Ponte da Etapa 9C sobre o ponto de extensão da 9A.
 *
 * O atraso configurável agrupa mensagens consecutivas antes de gerar.
 */
class DispatchConversationReplyGeneration
{
    public function __construct(
        private readonly ResponseModeResolver $modes,
        private readonly SystemSettingService $settings,
    ) {}

    public function handle(ConversationMessageEvaluated $event): void
    {
        if (! $this->modes->forFlow($event->state->flow)->generates()) {
            return;
        }

        if ($this->deterministicEngineJustReplied($event)) {
            return;
        }

        GenerateConversationReplyJob::dispatch($event->message->id)
            ->delay(now()->addSeconds($this->debounceSeconds($event)));
    }

    /**
     * Quanto esperar antes de gerar, agrupando o que a pessoa ainda vai
     * escrever.
     *
     * A espera cresce depois que a conversa engata. No começo, resposta rápida
     * sustenta a conversa: quem acabou de autorizar e ficar dois minutos sem
     * retorno acha que não funcionou. Depois de algumas trocas o problema se
     * inverte — a pessoa passa a escrever em blocos, manda a ideia numa
     * mensagem, o exemplo em outra e o motivo numa terceira, e responder a
     * primeira frase joga fora as duas seguintes.
     *
     * A espera maior custa tempo de quem responde e devolve contexto inteiro.
     * Só o job da mensagem mais nova gera: os anteriores desistem sozinhos ao
     * ver que chegou coisa nova.
     */
    private function debounceSeconds(ConversationMessageEvaluated $event): int
    {
        $padrao = max(0, (int) $this->settings->get('ai.response.debounce_seconds', 20));
        $estendido = max(0, (int) $this->settings->get('ai.response.extended_debounce_seconds', 90));
        $apartirDe = max(0, (int) $this->settings->get('ai.response.extended_debounce_after_turns', 3));

        return $event->state->followups_count >= $apartirDe
            ? max($padrao, $estendido)
            : $padrao;
    }

    /**
     * A 9A acabou de responder esta mensagem?
     *
     * Quando a pessoa autoriza a pesquisa, quem responde e o motor
     * determinístico, mandando a pergunta cadastrada. Gerar texto de IA para o
     * mesmo "sim" produzia sugestão sem função: ela nascia genérica, entupia a
     * caixa de aprovação e nunca poderia ser autoenviada, porque `permission_yes`
     * não esta — nem deveria estar — na allowlist de autoenvio.
     *
     * O mesmo vale entre uma pergunta e outra numa pesquisa de várias perguntas.
     *
     * A verificação exige que o motor tenha rodado: com a 9A desligada ou
     * bloqueada, a 9C continua sendo o único caminho de resposta e segue
     * gerando, que e a independência que a 9B/9C sempre tiveram.
     */
    private function deterministicEngineJustReplied(ConversationMessageEvaluated $event): bool
    {
        if (! $event->flowEngineRan) {
            return false;
        }

        return in_array($event->state->current_stage, [
            ConversationFlowStage::QuestionSelected,
            ConversationFlowStage::WaitingAnswer,
        ], true);
    }
}
