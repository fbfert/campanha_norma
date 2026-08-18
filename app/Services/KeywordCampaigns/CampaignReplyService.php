<?php

namespace App\Services\KeywordCampaigns;

use App\Enums\ConversationMessageOrigin;
use App\Enums\KeywordEnrollmentOutcome;
use App\Jobs\EnviarConfirmacaoDeCampanhaJob;
use App\Models\ConversationMessage;
use App\Models\KeywordCampaign;
use App\Services\Conversations\ConversationReplyService;
use App\Services\SystemSettingService;

/**
 * A resposta que a campanha manda para quem escreveu.
 *
 * Um texto por desfecho: inscrição aceita, já inscrito, fora da vigência. Fora
 * da vigência sem texto configurado é silêncio deliberado — campanha encerrada
 * que responde reabre a conversa com quem chegou tarde.
 */
class CampaignReplyService
{
    public function __construct(
        private readonly ConversationReplyService $replies,
        private readonly CampaignSurveyStarter $surveys,
        private readonly SystemSettingService $settings,
    ) {}

    /**
     * Enfileira a resposta que corresponde ao desfecho, se houver uma.
     */
    public function responder(
        KeywordCampaign $campaign,
        ConversationMessage $inbound,
        EnrollmentResult $resultado,
    ): ?ConversationMessage {
        $texto = $this->texto($campaign, $resultado->outcome);

        if ($texto === null) {
            return null;
        }

        $conversation = $inbound->conversation;

        if ($conversation === null || $conversation->contact === null) {
            return null;
        }

        /*
         | Confirmação e convite da pesquisa vão na MESMA mensagem.
         |
         | Separadas seriam duas mensagens no mesmo minuto para a mesma pessoa,
         | que é exatamente o volume que o limitador desta etapa existe para
         | conter. E, do lado de quem lê, "inscrição confirmada" seguido de
         | "posso te fazer uma pergunta?" é uma fala só.
         */
        $convite = $this->conviteDaPesquisa($campaign, $inbound, $resultado);

        if ($convite !== null) {
            $texto = $texto."\n\n".$convite;
        }

        $mensagem = $this->replies->createPending(
            conversation: $conversation,
            body: $texto,
            origin: ConversationMessageOrigin::Automation,
            eventType: 'keyword_campaign_reply_queued',
            eventDescription: 'Resposta de campanha enfileirada.',
            eventPayload: [
                'keyword_campaign_id' => $campaign->id,
                'outcome' => $resultado->outcome->value,
                'participation_id' => $resultado->participation?->id,
                'abre_pesquisa' => $convite !== null,
            ],
            auditAction: 'keyword_campaign.reply_queued',
            auditDescription: 'Resposta de campanha enfileirada.',
        );

        /*
         | O identificador da mensagem recebida viaja junto.
         |
         | Quando o fluxo for aberto, ele precisa nascer com essa mensagem já
         | marcada como processada — senão o motor da 9A leria a própria
         | palavra-chave como se fosse a resposta ao pedido de permissão.
         */
        EnviarConfirmacaoDeCampanhaJob::dispatch(
            $mensagem->id,
            $campaign->id,
            $convite !== null ? $inbound->id : null,
        )->onQueue($this->fila());

        return $mensagem;
    }

    /**
     * O texto de cada desfecho.
     *
     * Desfecho que não fala com a pessoa devolve nulo: limite atingido, lista
     * congelada e telefone inválido não têm resposta. Os dois primeiros porque
     * anunciar "acabaram as vagas" convida à discussão sobre quem chegou antes,
     * e o terceiro porque não há para onde responder.
     */
    public function texto(KeywordCampaign $campaign, KeywordEnrollmentOutcome $outcome): ?string
    {
        $texto = match ($outcome) {
            KeywordEnrollmentOutcome::Registrada,
            KeywordEnrollmentOutcome::EmRevisao => $campaign->confirmation_text,
            KeywordEnrollmentOutcome::JaInscrita => $campaign->already_enrolled_text,
            KeywordEnrollmentOutcome::ForaDeVigencia => $campaign->out_of_window_text,
            default => null,
        };

        $texto = trim((string) $texto);

        return $texto === '' ? null : $texto;
    }

    /**
     * O convite só existe quando há pesquisa para abrir de verdade.
     *
     * Três recusas, e cada uma tem uma razão diferente:
     *
     * - desfecho que não é inscrição nova. Quem já estava inscrito não pode ser
     *   convidado de novo, e quem chegou fora da vigência não entrou em nada.
     * - campanha sem fluxo. É o padrão: campanha que só sorteia não pergunta.
     * - conversa com pesquisa VIVA. A pessoa está no meio de outra, e abrir uma
     *   segunda faria duas perguntas concorrerem na mesma conversa. Viva, e não
     *   apenas existente: pesquisa encerrada ou com o prazo vencido é reaberta,
     *   senão a campanha alcançaria só quem nunca foi abordado — que na base de
     *   17/08/2026 eram 60 conversas de 129.
     */
    private function conviteDaPesquisa(
        KeywordCampaign $campaign,
        ConversationMessage $inbound,
        EnrollmentResult $resultado,
    ): ?string {
        if ($resultado->outcome !== KeywordEnrollmentOutcome::Registrada) {
            return null;
        }

        if (! $campaign->abrePesquisa()) {
            return null;
        }

        if ($this->surveys->temPesquisaViva($inbound->conversation_id)) {
            return null;
        }

        return $campaign->conviteDePesquisa();
    }

    public function fila(): string
    {
        return (string) $this->settings->get('keyword_campaigns.send_queue', 'keyword-campaigns-send');
    }
}
