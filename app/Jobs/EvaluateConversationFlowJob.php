<?php

namespace App\Jobs;

use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Services\ConversationAutomation\ConversationFlowService;
use App\Services\InboundAttendance\InboundAttendanceService;
use App\Services\KeywordCampaigns\KeywordCampaignTrigger;
use App\Services\SystemSettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Avalia o fluxo conversacional após uma mensagem recebida.
 * Nunca envia diretamente: apenas decide e cria mensagem pendente pelo serviço.
 */
class EvaluateConversationFlowJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function __construct(private readonly int $conversationMessageId)
    {
        $this->onQueue(app(SystemSettingService::class)->get('conversation_automation.queue', 'conversation-automation'));
    }

    public function handle(ConversationFlowService $flows): void
    {
        $message = ConversationMessage::with('conversation.contact')->find($this->conversationMessageId);

        if (! $message) {
            return;
        }

        // Trava por conversa para não permitir dois workers avaliando o mesmo fluxo.
        $lock = Cache::lock("conversation-flow:{$message->conversation_id}", 60);

        if (! $lock->get()) {
            $this->release(10);

            return;
        }

        try {
            /*
             | Etapa 10: a campanha por palavra-chave é avaliada antes de tudo.
             |
             | Fica aqui, e não num job irmão despachado ao lado deste, porque
             | os dois criariam contato para o mesmo número desconhecido em
             | paralelo — e `contacts.phone_normalized` tem índice, não chave
             | única. A trava por conversa que este job já segura resolve a
             | corrida de graça.
             |
             | Vem antes da decisão "esta conversa tem fluxo?" de propósito: o
             | roteamento de entrada só alcança conversa sem estado, e quem está
             | no meio de uma pesquisa é justamente quem já provou que responde.
             |
             | O gatilho não lê nem escreve `conversation_flow_states`, não move
             | `last_processed_message_id` e não chama o motor. Sem campanha
             | vigente cadastrada, sai na primeira leitura de cache.
             */
            $gatilho = app(KeywordCampaignTrigger::class);
            $campanhas = $gatilho->avaliar($message);
            $campanhaAtendeu = $gatilho->algumAtendeu($campanhas);

            /*
             | Mensagem que era só a palavra-chave para aqui.
             |
             | Ela já virou inscrição e já foi respondida. Entregá-la também ao
             | motor da 9A a transforma em resposta de pesquisa: foi o que
             | gravou "batata" como opinião sobre o problema mais urgente da
             | cidade, em 17/08/2026, e fez a pergunta seguinte sair junto da
             | confirmação.
             */
            if ($gatilho->consumiuAMensagem()) {
                return;
            }

            /*
             | Conversa sem fluxo é quem escreveu primeiro.
             |
             | O motor da 9A sai calado nesse caso, e sair calado estava certo
             | enquanto todo fluxo nascia de um lote: não havia o que continuar.
             | Agora há — o atendimento de entrada abre a conversa, e é aqui que
             | ele entra porque este job já segura a trava por conversa, que é o
             | que impede duas mensagens seguidas abrirem dois atendimentos.
             |
             | Ele decide sozinho se é caso de agir. Não agindo, tudo segue como
             | antes: `handleIncomingMessage` continua sendo chamado e continua
             | saindo calado.
             */
            if (! $campanhaAtendeu && ! ConversationFlowState::query()->where('conversation_id', $message->conversation_id)->exists()) {
                app(InboundAttendanceService::class)->handle($message);
            }

            // Abrindo ou não, a idempotência do estado cobre o resto: o
            // atendimento já marcou esta mensagem como processada, e o motor
            // não a avalia duas vezes.
            $flows->handleIncomingMessage($message);
        } finally {
            $lock->release();
        }
    }
}
