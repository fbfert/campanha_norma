<?php

namespace App\Jobs;

use App\Enums\ConversationMessageStatus;
use App\Enums\ConversationStatus;
use App\Models\ConversationMessage;
use App\Services\AuditLogger;
use App\Services\ConversationAutomation\ConversationAutomationGuard;
use App\Services\Conversations\ConversationEventService;
use App\Services\SystemSettingService;
use App\Services\WhatsApp\WhatsAppProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Envia a mensagem automática já criada, pelo mesmo caminho da resposta manual.
 */
class SendAutomatedConversationReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param  bool  $safetyNet  Aviso do piso de "ninguém fica sem resposta".
     *                           Ele não é mensagem do fluxo, e aplicar a ele as
     *                           condições do fluxo o bloqueava justamente nas
     *                           conversas onde alguém está esperando.
     */
    public function __construct(private readonly int $messageId, private readonly bool $safetyNet = false)
    {
        $this->onQueue(app(SystemSettingService::class)->get('conversation_automation.send_queue', 'conversation-automation-send'));
    }

    public function handle(
        WhatsAppProviderManager $providers,
        ConversationEventService $events,
        ConversationAutomationGuard $guard,
        AuditLogger $audit,
    ): void {
        $message = ConversationMessage::with('conversation.contact', 'conversation.flowState')->find($this->messageId);

        if (! $message || $message->status === ConversationMessageStatus::Sent) {
            return;
        }

        $state = $message->conversation?->flowState;

        // Revalida no momento do envio: pausa ou opt-out entre a criação e o envio cancela.
        //
        // O aviso da rede de segurança passa por uma porta própria, que mantém
        // o que protege a pessoa — opt-out, contato inativo, horário — e larga
        // o que só descreve o estado da pesquisa.
        if ($state || $this->safetyNet) {
            // A conversa vai junto: sem estado de fluxo não ha de onde tirar o
            // contato, e era assim que o aviso morria em conversa que nunca
            // entrou em pesquisa — justamente a que mais precisa dele.
            $check = $this->safetyNet
                ? $guard->canSendSafetyNet($state, $message->conversation)
                : $guard->canSend($state);
            if (! $check['allowed']) {
                $message->update([
                    'status' => ConversationMessageStatus::Failed,
                    'failed_at' => now(),
                    'error_code' => 'AUTOMATION_BLOCKED',
                    'error_message' => 'Envio automático bloqueado antes do disparo.',
                ]);
                $events->record($message->conversation, 'automated_reply_blocked', 'Envio automático bloqueado.', $message, null, ['reason' => $check['reason']]);

                return;
            }
        }

        $lock = Cache::lock("conversation:{$message->conversation_id}:reply", 120);
        if (! $lock->get()) {
            $this->release(15);

            return;
        }

        try {
            $message->update(['status' => ConversationMessageStatus::Processing]);
            $result = $providers->provider()->sendMessage((string) $message->recipient_phone_snapshot, (string) $message->body, (string) $message->request_id);

            $message->update([
                'status' => ConversationMessageStatus::Sent,
                'external_message_id' => $result->externalMessageId,
                'sent_at' => $result->sentAt ?? now(),
            ]);

            $message->conversation->update([
                'status' => ConversationStatus::WaitingContact,
                'last_message_direction' => $message->direction,
                'last_message_at' => now(),
                'last_outgoing_message_at' => now(),
            ]);

            $events->record($message->conversation, 'automated_reply_sent', 'Mensagem automática enviada.', $message);
            $audit->log('conversation_automation.message_sent', 'Mensagem automática enviada.', $message, null, ['conversation_id' => $message->conversation_id]);
        } catch (\Throwable $exception) {
            $timeout = str_contains($exception->getMessage(), 'timeout');

            $message->update([
                'status' => $timeout ? ConversationMessageStatus::Unknown : ConversationMessageStatus::Failed,
                'failed_at' => now(),
                'error_code' => $timeout ? 'AUTOMATED_REPLY_RESULT_UNKNOWN' : 'AUTOMATED_REPLY_FAILED',
                'error_message' => 'Falha ao enviar mensagem automática.',
            ]);

            $events->record($message->conversation, 'automated_reply_failed', 'Falha ao enviar mensagem automática.', $message);
            $audit->log('conversation_automation.message_failed', 'Falha ao enviar mensagem automática.', $message, null, ['conversation_id' => $message->conversation_id]);
        } finally {
            $lock->release();
        }
    }
}
