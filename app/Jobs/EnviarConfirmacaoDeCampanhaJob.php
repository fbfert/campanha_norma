<?php

namespace App\Jobs;

use App\Enums\ContactStatus;
use App\Enums\ConversationMessageStatus;
use App\Enums\ConversationStatus;
use App\Models\ConversationMessage;
use App\Models\KeywordCampaign;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationEventService;
use App\Services\KeywordCampaigns\CampaignSurveyStarter;
use App\Services\KeywordCampaigns\ConfirmationThrottle;
use App\Services\SystemSettingService;
use App\Services\WhatsApp\WhatsAppProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envia a resposta da campanha, sob o teto global.
 *
 * Não passa por `ConversationAutomationGuard`: as condições dele descrevem o
 * estado de uma pesquisa, e esta mensagem não pertence a pesquisa nenhuma —
 * aplicá-las bloquearia a confirmação justamente de quem já está respondendo
 * uma. O que protege a pessoa é conferido aqui, de forma explícita: opt-out e
 * contato inativo.
 */
class EnviarConfirmacaoDeCampanhaJob implements ShouldQueue
{
    use Queueable;

    /**
     * Tentativas altas de propósito.
     *
     * Recusa do limitador não é falha: é "não agora", e cada adiamento consome
     * uma tentativa. Numa rajada em que o teto é de vinte por minuto, quem está
     * no fim da fila é adiado muitas vezes antes de sair, e três tentativas
     * transformariam adiamento em confirmação perdida.
     */
    public int $tries = 50;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param  int|null  $inboundMessageId  Mensagem que trouxe a palavra-chave.
     *                                      Preenchida só quando esta confirmação
     *                                      carrega o convite da pesquisa.
     */
    public function __construct(
        private readonly int $messageId,
        private readonly int $campaignId,
        private readonly ?int $inboundMessageId = null,
    ) {
        $this->onQueue(app(SystemSettingService::class)->get('keyword_campaigns.send_queue', 'keyword-campaigns-send'));
    }

    public function handle(
        WhatsAppProviderManager $providers,
        ConfirmationThrottle $throttle,
        ConversationEventService $events,
        AuditLogger $audit,
    ): void {
        $message = ConversationMessage::with('conversation.contact')->find($this->messageId);

        if (! $message || $message->status === ConversationMessageStatus::Sent) {
            return;
        }

        $contact = $message->conversation?->contact;

        if (! $contact || $contact->do_not_contact || $contact->status !== ContactStatus::Active) {
            $message->update([
                'status' => ConversationMessageStatus::Failed,
                'failed_at' => now(),
                'error_code' => 'KEYWORD_CAMPAIGN_REPLY_BLOCKED',
                'error_message' => 'Resposta de campanha bloqueada antes do disparo.',
            ]);
            $events->record($message->conversation, 'keyword_campaign_reply_blocked', 'Resposta de campanha bloqueada.', $message);

            return;
        }

        /*
         | A janela de horário da automação de conversas não vale aqui.
         |
         | Quem escreve às 23h está com o celular na mão. Segurar a confirmação
         | até as 8h produz a segunda e a terceira mensagem da mesma pessoa
         | perguntando se deu certo — o que é pior para a reputação do número do
         | que ter respondido, além de deixar a pessoa achando que não entrou.
         */
        $decisao = $throttle->reservar();

        if (! $decisao->permitida) {
            /*
             | Adiado, nunca descartado.
             |
             | A cota só é consumida quando a vaga é reservada de verdade, logo
             | acima. O projeto tem o padrão inverso em outro lugar — incrementar
             | na criação e ainda poder bloquear no envio — e ele infla contador
             | com mensagem que nunca saiu.
             */
            $this->release($decisao->tentarEmSegundos);

            return;
        }

        try {
            $message->update(['status' => ConversationMessageStatus::Processing]);

            $resultado = $providers->provider()->sendMessage(
                (string) $message->recipient_phone_snapshot,
                (string) $message->body,
                (string) $message->request_id,
            );

            $message->update([
                'status' => ConversationMessageStatus::Sent,
                'external_message_id' => $resultado->externalMessageId,
                'sent_at' => $resultado->sentAt ?? now(),
            ]);

            $message->conversation->update([
                'status' => ConversationStatus::WaitingContact,
                'last_message_direction' => $message->direction,
                'last_message_at' => now(),
                'last_outgoing_message_at' => now(),
            ]);

            $events->record($message->conversation, 'keyword_campaign_reply_sent', 'Resposta de campanha enviada.', $message);
            $audit->log('keyword_campaign.reply_sent', 'Resposta de campanha enviada.', $message, null, [
                'keyword_campaign_id' => $this->campaignId,
            ]);

            $this->abrirPesquisa();
            $this->verificarAlarmeDeRajada();
        } catch (\Throwable $excecao) {
            $timeout = str_contains($excecao->getMessage(), 'timeout');

            $message->update([
                'status' => $timeout ? ConversationMessageStatus::Unknown : ConversationMessageStatus::Failed,
                'failed_at' => now(),
                'error_code' => $timeout ? 'KEYWORD_CAMPAIGN_REPLY_UNKNOWN' : 'KEYWORD_CAMPAIGN_REPLY_FAILED',
                'error_message' => 'Falha ao enviar resposta de campanha.',
            ]);

            $events->record($message->conversation, 'keyword_campaign_reply_failed', 'Falha ao enviar resposta de campanha.', $message);
        }
    }

    /**
     * Abre a pesquisa, depois de a confirmação ter saído de verdade.
     *
     * Depois, e não antes. O motor da 9A coloca a conversa em
     * `waiting_permission` assumindo que a pergunta já foi feita — e numa
     * rajada a confirmação pode ficar minutos na fila esperando o limitador.
     * Abrindo o fluxo na hora de enfileirar, qualquer coisa que a pessoa
     * escrevesse nesse intervalo seria lida como resposta a um pedido que ela
     * ainda não recebeu.
     *
     * É o mesmo momento que o lote escolhe: `RecipientProcessingService` chama
     * `activateForConversation` depois do envio bem-sucedido, não antes.
     */
    private function abrirPesquisa(): void
    {
        if ($this->inboundMessageId === null) {
            return;
        }

        $campanha = KeywordCampaign::with('conversationFlow')->find($this->campaignId);
        $inbound = ConversationMessage::with('conversation')->find($this->inboundMessageId);
        $flow = $campanha?->conversationFlow;

        if (! $flow || ! $inbound?->conversation) {
            return;
        }

        // O starter abre, ou reabre quando a conversa já teve uma pesquisa
        // morta. Pesquisa viva ele não toca, e devolve nulo.
        app(CampaignSurveyStarter::class)->abrir($inbound->conversation, $flow, $inbound);
    }

    /**
     * Alarme de rajada.
     *
     * Não freia nada: o freio é o limitador. Isto existe para que alguém saiba
     * que a divulgação pegou mais do que se esperava enquanto ainda está
     * acontecendo, e não no relatório do dia seguinte.
     */
    private function verificarAlarmeDeRajada(): void
    {
        $campanha = KeywordCampaign::find($this->campaignId);

        if (! $campanha || ! $campanha->hourly_alert_threshold) {
            return;
        }

        // Já avisado nesta hora: um alarme por hora basta para chamar atenção,
        // e mais que isso vira ruído que ninguém lê.
        if ($campanha->hourly_alert_raised_at?->greaterThan(now()->subHour())) {
            return;
        }

        $naUltimaHora = $campanha->participations()
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($naUltimaHora < $campanha->hourly_alert_threshold) {
            return;
        }

        $campanha->forceFill(['hourly_alert_raised_at' => now()])->save();

        app(AuditLogger::class)->log(
            'keyword_campaign.hourly_threshold_reached',
            "A campanha \"{$campanha->name}\" recebeu {$naUltimaHora} inscrições na última hora.",
            $campanha,
            null,
            ['count' => $naUltimaHora, 'threshold' => $campanha->hourly_alert_threshold],
        );
    }
}
