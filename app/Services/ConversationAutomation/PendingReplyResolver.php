<?php

namespace App\Services\ConversationAutomation;

use App\Contracts\PairsBySession;
use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageOrigin;
use App\Enums\ConversationMessageStatus;
use App\Enums\ReplySuggestionStatus;
use App\Enums\WhatsAppConnectionStatus;
use App\Jobs\GenerateConversationReplyJob;
use App\Jobs\SendAutomatedConversationReplyJob;
use App\Models\Conversation;
use App\Models\ConversationEvent;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Models\ConversationReplySuggestion;
use App\Models\WhatsAppConnection;
use App\Services\Conversations\ConversationEventService;
use App\Services\Conversations\InternalNumbers;
use App\Services\Conversations\ConversationReplyService;
use App\Services\ResponseGeneration\ConversationSuggestionService;
use App\Services\SystemSettingService;
use App\Services\WhatsApp\WhatsAppProviderManager;

/**
 * Garante retorno a uma mensagem que ficou sem resposta.
 *
 * A regra é simples de enunciar e foi difícil de cumprir: quem escreve recebe
 * resposta. A automação tem várias saídas legítimas que terminam em silêncio —
 * pesquisa encerrada, conversa encaminhada para gente, job perdido, resposta
 * que o classificador não entendeu. Cada uma faz sentido isolada, e o efeito
 * somado é sempre o mesmo para quem escreveu.
 *
 * A ordem aqui é deliberada. Primeiro tenta-se responder de verdade: a IA lê o
 * que a pessoa disse, com a taxonomia e a base de conhecimento, e escreve. Só
 * quando isso não dá — o modelo não teve confiança, pediu revisão humana, o
 * texto não passou na validação — é que sai o agradecimento. O agradecimento é
 * o piso, nunca o primeiro recurso: mandá-lo antes de tentar responder seria
 * transformar toda conversa em protocolo.
 */
class PendingReplyResolver
{
    /** Marca no histórico da conversa; é por ela que sabemos o que já foi tratado. */
    public const ACK_EVENT = 'pending_reply_acknowledged';

    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly ConversationSuggestionService $suggestions,
        private readonly ConversationAutomatedReplyService $replies,
        private readonly ConversationEventService $events,
        private readonly ConversationReplyService $outgoing,
    ) {}

    /**
     * @return array{outcome: string, reason: ?string}
     */
    public function resolve(Conversation $conversa, ConversationMessage $mensagem, bool $simular = false): array
    {
        if ($this->alreadyHandled($conversa, $mensagem)) {
            return ['outcome' => 'ja_tratada', 'reason' => null];
        }

        /*
         | A equipe não é atendida pela própria rede.
         |
         | O sistema não distingue quem atende de quem é atendido: a conversa de
         | trabalho com a candidata caiu no mesmo funil de quem responde a uma
         | pesquisa, e ela recebeu "Recebemos sua mensagem, nossa equipe vai ler
         | com atenção" duas vezes no mesmo segundo. Nenhuma regra de conteúdo
         | pega isso — naquele dia ela tinha escrito "Oiii".
         */
        if (app(InternalNumbers::class)->coversConversation($conversa)) {
            return ['outcome' => 'numero_interno', 'reason' => null];
        }

        /*
         | Sem sessão conectada não se tenta: o envio falharia com certeza, e
         | cada tentativa deixa uma linha na conversa.
         |
         | Duas conversas chegaram a 771 mensagens assim. A sessão caiu numa
         | sexta à noite e voltou 64 horas depois; a rede de segurança tentou
         | mandar o mesmo agradecimento a cada cinco minutos e gravou 767
         | falhas em cada uma. Metade da tabela de mensagens virou repetição
         | de duas frases que nunca saíram.
         |
         | Não é tentativa perdida: enquanto não há sessão, a pessoa está
         | inalcançável de qualquer jeito. Voltando a conexão, a execução
         | seguinte tenta de novo.
         */
        if (! $this->providerCanSend()) {
            return ['outcome' => 'sem_conexao', 'reason' => null];
        }

        if ($this->attemptsExhausted($conversa, $mensagem)) {
            return ['outcome' => 'tentativas_esgotadas', 'reason' => null];
        }

        $sugestao = $this->usableSuggestion($conversa, $mensagem, $simular);

        if ($sugestao) {
            $veredito = $this->assess($sugestao);

            if ($veredito === null) {
                if ($simular) {
                    return ['outcome' => 'responderia_com_ia', 'reason' => null];
                }

                $envio = $this->suggestions->send($sugestao, null, false, true);

                if ($envio['sent']) {
                    return ['outcome' => 'respondida_com_ia', 'reason' => null];
                }

                // A porta de envio recusou por um motivo que a avaliação não
                // alcança — texto reprovado, conversa pausada, fundamentação.
                // Ainda assim a pessoa não pode ficar sem nada.
                $veredito = $envio['reason'];
            }

            return $this->acknowledge($conversa, $mensagem, $veredito, $simular);
        }

        return $this->acknowledge($conversa, $mensagem, 'sem_sugestao_utilizavel', $simular);
    }

    /**
     * Por que esta sugestão não pode sair sozinha, ou `null` se pode.
     *
     * A rede de segurança contorna o autoenvio comum, que pode estar desligado
     * de propósito. Contornar exige ser mais exigente, não menos: só passa
     * texto em que o próprio modelo declarou confiança alta e não pediu ajuda.
     */
    private function assess(ConversationReplySuggestion $sugestao): ?string
    {
        if ($sugestao->requires_human_review) {
            return 'sinalizada_para_revisao';
        }

        if ($sugestao->handoff_reason !== null) {
            return 'pedido_de_atendimento_humano';
        }

        if (! $sugestao->action->producesText() || $sugestao->outgoingText() === '') {
            return 'sugestao_sem_texto';
        }

        $limiar = (float) $this->settings->get('ai.response.safety_net_min_confidence', 0.92);

        if ($sugestao->confidence === null || (float) $sugestao->confidence < $limiar) {
            return 'confianca_insuficiente';
        }

        return null;
    }

    /**
     * Sugestão viva para esta mensagem, gerando na hora se ainda não existir.
     *
     * A geração é síncrona de propósito: sem ela o comando terminaria sem saber
     * se houve resposta, e a decisão entre responder e agradecer ficaria para a
     * rodada seguinte — mais quinze minutos de silêncio para quem escreveu.
     */
    private function usableSuggestion(Conversation $conversa, ConversationMessage $mensagem, bool $simular): ?ConversationReplySuggestion
    {
        $existente = $this->liveSuggestion($mensagem);

        if ($existente || $simular) {
            return $existente;
        }

        GenerateConversationReplyJob::dispatchSync($mensagem->id);

        return $this->liveSuggestion($mensagem);
    }

    private function liveSuggestion(ConversationMessage $mensagem): ?ConversationReplySuggestion
    {
        return ConversationReplySuggestion::query()
            ->where('source_message_id', $mensagem->id)
            ->whereIn('status', ReplySuggestionStatus::liveValues())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Piso: avisa que a mensagem chegou e será respondida.
     */
    private function acknowledge(Conversation $conversa, ConversationMessage $mensagem, ?string $motivo, bool $simular): array
    {
        /*
         | O aviso institucional pressupõe uma conversa que já aconteceu.
         |
         | "Nossa equipe vai ler com atenção" dito a quem acabou de escrever a
         | primeira frase soa como dispensa, e encerra uma conversa que ainda
         | nem tinha começado. Depois de algumas idas e voltas a mesma frase
         | soa como cuidado, porque há o que ler.
         |
         | Abaixo do piso vai um texto curto. Trocar o aviso por silêncio
         | reabriria o buraco que a rede de segurança existe para fechar.
         */
        $idasEVoltas = $this->completedExchanges($conversa);
        $minimo = (int) $this->settings->get('conversation_automation.unanswered_ack_min_exchanges', 5);

        $chave = $idasEVoltas >= $minimo
            ? 'conversation_automation.unanswered_ack_text'
            : 'conversation_automation.unanswered_ack_short_text';

        $texto = trim((string) $this->settings->get($chave, ''));

        if ($texto === '') {
            return ['outcome' => 'sem_texto_de_aviso', 'reason' => $motivo];
        }

        if ($this->withinCooldown($conversa)) {
            return ['outcome' => 'intervalo_minimo', 'reason' => $motivo];
        }

        if ($simular) {
            return ['outcome' => 'agradeceria', 'reason' => $motivo];
        }

        $state = ConversationFlowState::query()->where('conversation_id', $conversa->id)->first();

        // Com fluxo, o aviso sai pelo caminho da automação: transparência,
        // contadores e fila iguais aos de qualquer texto do sistema. Sem fluxo
        // — conversa que nunca entrou em pesquisa — vai pelo serviço de saída
        // comum. Ignorar essas deixaria justamente quem mais ficou no vácuo
        // sem retorno.
        $enviada = $state && ! $state->is_paused && ! $state->current_stage->isTerminal()
            ? $this->replies->queue($state, $texto, 'pending_reply_ack_queued', safetyNet: true)
            : $this->sendWithoutFlow($conversa, $texto);

        if (! $enviada) {
            return ['outcome' => 'falhou', 'reason' => $motivo];
        }

        // O evento aponta para o aviso, e não para a mensagem que o provocou.
        // Sem esse vínculo não havia como saber, depois, se o aviso chegou a
        // sair — e um aviso que falhou no envio segurava a conversa em
        // intervalo mínimo por horas, como se ela já tivesse sido respondida.
        $this->events->record(
            $conversa,
            self::ACK_EVENT,
            'Aviso de recebimento enfileirado por falta de resposta.',
            $enviada,
            null,
            ['motivo' => $motivo, 'respondendo_mensagem_id' => $mensagem->id],
        );

        return ['outcome' => $idasEVoltas >= $minimo ? 'agradecida' : 'agradecida_conversa_curta', 'reason' => $motivo];
    }

    /**
     * Idas e voltas completas na conversa.
     *
     * Uma ida e volta é o sistema falar e a pessoa responder. Conta-se a
     * passagem de saída para entrada: duas mensagens nossas seguidas não viram
     * duas idas e voltas, e três respostas seguidas dela também não — o que se
     * quer medir é quantas vezes a conversa de fato voltou.
     */
    private function completedExchanges(Conversation $conversa): int
    {
        $direcoes = ConversationMessage::query()
            ->where('conversation_id', $conversa->id)
            ->whereIn('status', [
                \App\Enums\ConversationMessageStatus::Sent,
                \App\Enums\ConversationMessageStatus::Received,
            ])
            ->orderBy('id')
            ->pluck('direction');

        $voltas = 0;
        $anterior = null;

        foreach ($direcoes as $direcao) {
            if ($anterior === \App\Enums\ConversationMessageDirection::Outgoing
                && $direcao === \App\Enums\ConversationMessageDirection::Incoming) {
                $voltas++;
            }

            $anterior = $direcao;
        }

        return $voltas;
    }

    /**
     * Esta mensagem já teve retorno?
     *
     * Vale qualquer saída posterior a ela — resposta do fluxo, texto aprovado
     * por uma pessoa ou aviso desta mesma rede. O que importa é a pessoa ter
     * recebido algo depois de falar, não quem escreveu.
     */
    /**
     * O provedor tem como enviar agora.
     *
     * Só o WhatsApp Web perde a sessão desse jeito. Provedor que não pareia —
     * a API oficial — não tem esse estado, e ali a checagem só atrasaria o
     * envio, por isso o contrato decide em vez de uma condição fixa.
     */
    private function providerCanSend(): bool
    {
        if (! app(WhatsAppProviderManager::class)->provider() instanceof PairsBySession) {
            return true;
        }

        $conexao = WhatsAppConnection::query()->latest('id')->first();

        /*
         | Só barra quando se sabe que a sessão caiu.
         |
         | Sem registro nenhum não dá para afirmar nada, e presumir queda
         | silenciaria a rede de segurança numa instalação nova ou antes da
         | primeira leitura de estado — exatamente o buraco que ela existe para
         | fechar. Na dúvida, tenta; o teto de tentativas cobre o resto.
         */
        return $conexao === null || $conexao->status === WhatsAppConnectionStatus::Connected;
    }

    /**
     * Teto de tentativas para a mesma mensagem.
     *
     * A conexão cobre a causa conhecida, e esta é a rede embaixo dela: falha
     * que persiste por outro motivo — número que o WhatsApp recusa, por exemplo
     * — pararia de tentar só quando alguém percebesse.
     *
     * Contamos as saídas que falharam depois da mensagem que disparou a
     * resposta. Zerar isso não é preciso: se a pessoa escrever de novo, a
     * mensagem nova é outro gatilho, com contagem própria.
     */
    private function attemptsExhausted(Conversation $conversa, ConversationMessage $mensagem): bool
    {
        $teto = (int) $this->settings->get('conversation_automation.unanswered_max_attempts', 5);

        if ($teto <= 0) {
            return false;
        }

        return ConversationMessage::query()
            ->where('conversation_id', $conversa->id)
            ->where('direction', ConversationMessageDirection::Outgoing)
            ->where('id', '>', $mensagem->id)
            ->where('status', ConversationMessageStatus::Failed)
            ->count() >= $teto;
    }

    private function alreadyHandled(Conversation $conversa, ConversationMessage $mensagem): bool
    {
        return ConversationMessage::query()
            ->where('conversation_id', $conversa->id)
            ->where('direction', \App\Enums\ConversationMessageDirection::Outgoing)
            ->where('id', '>', $mensagem->id)
            // Saída que falhou não é resposta. Ela contava como tal, e por isso
            // a rede de segurança desistia de uma conversa depois da primeira
            // tentativa recusada: a linha ficava lá, marcada como falha, e
            // bastava existir para a conversa nunca mais ser tentada.
            ->whereNotIn('status', [
                \App\Enums\ConversationMessageStatus::Failed,
                \App\Enums\ConversationMessageStatus::Cancelled,
            ])
            ->exists();
    }

    /**
     * Intervalo mínimo entre dois agradecimentos na mesma conversa.
     *
     * Sem isto, quem manda três mensagens ao longo de uma tarde recebe três
     * vezes a mesma frase, e o cuidado vira protocolo. A resposta escrita pela
     * IA não passa por aqui: ela é diferente a cada vez.
     */
    private function withinCooldown(Conversation $conversa): bool
    {
        $horas = (int) $this->settings->get('conversation_automation.unanswered_ack_cooldown_hours', 6);

        if ($horas <= 0) {
            return false;
        }

        return ConversationEvent::query()
            ->where('conversation_id', $conversa->id)
            ->where('event_type', self::ACK_EVENT)
            ->where('created_at', '>=', now()->subHours($horas))
            // Aviso que não saiu não segura nada: contá-lo deixava a conversa
            // em silêncio pelas horas do intervalo por causa de uma mensagem
            // que ninguém recebeu.
            ->where(fn ($query) => $query
                ->whereNull('conversation_message_id')
                ->orWhereHas('message', fn ($mensagem) => $mensagem->whereNotIn('status', [
                    \App\Enums\ConversationMessageStatus::Failed,
                    \App\Enums\ConversationMessageStatus::Cancelled,
                ])))
            ->exists();
    }

    private function sendWithoutFlow(Conversation $conversa, string $texto): ?ConversationMessage
    {
        $mensagem = $this->outgoing->createPending(
            conversation: $conversa,
            body: $texto,
            origin: ConversationMessageOrigin::Automation,
            eventType: 'pending_reply_ack_queued',
            eventDescription: 'Aviso de recebimento enfileirado.',
        );

        SendAutomatedConversationReplyJob::dispatch($mensagem->id, safetyNet: true)
            ->onQueue($this->settings->get('conversation_automation.send_queue', 'conversation-automation-send'));

        return $mensagem;
    }
}
