<?php

namespace App\Jobs;

use App\Enums\ContactStatus;
use App\Enums\ConversationMessageOrigin;
use App\Enums\ConversationMessageStatus;
use App\Enums\ConversationStatus;
use App\Models\KeywordCampaignCoupon;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationEventService;
use App\Services\Conversations\ConversationReplyService;
use App\Services\KeywordCampaigns\ConfirmationThrottle;
use App\Services\KeywordCampaigns\CouponService;
use App\Services\SystemSettingService;
use App\Services\WhatsApp\WhatsAppProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Entrega o cupom ao ganhador.
 *
 * O código é montado no momento do envio e não é gravado em lugar nenhum: a
 * mensagem que fica no histórico carrega a referência do cupom, não o cupom.
 * Um histórico de conversa é lido por muito mais gente do que a tela que exige
 * permissão para mostrar o código.
 */
class EntregarCupomDeCampanhaJob implements ShouldQueue
{
    use Queueable;

    /** Mesma razão do job de confirmação: adiamento consome tentativa. */
    public int $tries = 50;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(private readonly int $couponId)
    {
        $this->onQueue(app(SystemSettingService::class)->get('keyword_campaigns.send_queue', 'keyword-campaigns-send'));
    }

    public function handle(
        WhatsAppProviderManager $providers,
        ConfirmationThrottle $throttle,
        CouponService $coupons,
        ConversationReplyService $replies,
        ConversationEventService $events,
        AuditLogger $audit,
    ): void {
        $cupom = KeywordCampaignCoupon::with('campaign', 'participation.contact', 'participation.message.conversation')
            ->find($this->couponId);

        if (! $cupom || $cupom->delivered_at !== null) {
            return;
        }

        $participacao = $cupom->participation;
        $contato = $participacao?->contact;
        $conversa = $participacao?->message?->conversation;

        if (! $participacao || ! $contato || ! $conversa) {
            return;
        }

        if ($contato->do_not_contact || $contato->status !== ContactStatus::Active) {
            $events->record($conversa, 'keyword_campaign_coupon_blocked', 'Entrega de cupom bloqueada.', null, null, [
                'coupon_reference' => $cupom->reference,
            ]);

            return;
        }

        // O mesmo teto global da confirmação: um lote de ganhadores é uma
        // rajada como outra qualquer.
        $decisao = $throttle->reservar();

        if (! $decisao->permitida) {
            $this->release($decisao->tentarEmSegundos);

            return;
        }

        $codigo = $coupons->revelar($cupom);
        $texto = "Parabéns! Você foi sorteado. Seu código de acesso é: {$codigo}";

        /*
         | A linha do histórico não guarda o código.
         |
         | `createPending` grava `body` no banco, então o corpo gravado é a
         | referência; o texto com o código só existe na variável que vai para o
         | provedor. Quem abrir a conversa vê que um cupom foi enviado e qual,
         | sem conseguir resgatá-lo.
         */
        $mensagem = $replies->createPending(
            conversation: $conversa,
            body: "[Cupom enviado ao ganhador: {$cupom->reference}]",
            origin: ConversationMessageOrigin::Automation,
            eventType: 'keyword_campaign_coupon_queued',
            eventDescription: 'Cupom do sorteio enviado.',
            eventPayload: [
                'keyword_campaign_id' => $cupom->keyword_campaign_id,
                'coupon_reference' => $cupom->reference,
                'participation_id' => $participacao->id,
            ],
        );

        try {
            $mensagem->update(['status' => ConversationMessageStatus::Processing]);

            $resultado = $providers->provider()->sendMessage(
                (string) $contato->phone_normalized,
                $texto,
                (string) $mensagem->request_id,
            );

            $mensagem->update([
                'status' => ConversationMessageStatus::Sent,
                'external_message_id' => $resultado->externalMessageId,
                'sent_at' => $resultado->sentAt ?? now(),
            ]);

            $conversa->update([
                'status' => ConversationStatus::WaitingContact,
                'last_message_at' => now(),
                'last_outgoing_message_at' => now(),
            ]);

            $coupons->marcarEntregue($cupom);

            $audit->log(
                'keyword_campaign.coupon_delivered',
                'Cupom entregue ao ganhador.',
                $cupom,
                null,
                // Referência, nunca o código.
                ['coupon_reference' => $cupom->reference, 'participation_id' => $participacao->id],
            );
        } catch (\Throwable) {
            $mensagem->update([
                'status' => ConversationMessageStatus::Failed,
                'failed_at' => now(),
                'error_code' => 'KEYWORD_COUPON_DELIVERY_FAILED',
                // A mensagem de erro do provedor pode ecoar o corpo enviado, e
                // o corpo enviado tem o código dentro.
                'error_message' => 'Falha ao entregar o cupom do sorteio.',
            ]);

            $events->record($conversa, 'keyword_campaign_coupon_failed', 'Falha ao entregar o cupom.', $mensagem, null, [
                'coupon_reference' => $cupom->reference,
            ]);
        }
    }
}
