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
 * Envia a mensagem automatica ja criada, pelo mesmo caminho da resposta manual.
 */
class SendAutomatedConversationReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(private readonly int $messageId)
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

        // Revalida no momento do envio: pausa ou opt-out entre a criacao e o envio cancela.
        if ($state) {
            $check = $guard->canSend($state);
            if (! $check['allowed']) {
                $message->update([
                    'status' => ConversationMessageStatus::Failed,
                    'failed_at' => now(),
                    'error_code' => 'AUTOMATION_BLOCKED',
                    'error_message' => 'Envio automatico bloqueado antes do disparo.',
                ]);
                $events->record($message->conversation, 'automated_reply_blocked', 'Envio automatico bloqueado.', $message, null, ['reason' => $check['reason']]);

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

            $events->record($message->conversation, 'automated_reply_sent', 'Mensagem automatica enviada.', $message);
            $audit->log('conversation_automation.message_sent', 'Mensagem automatica enviada.', $message, null, ['conversation_id' => $message->conversation_id]);
        } catch (\Throwable $exception) {
            $timeout = str_contains($exception->getMessage(), 'timeout');

            $message->update([
                'status' => $timeout ? ConversationMessageStatus::Unknown : ConversationMessageStatus::Failed,
                'failed_at' => now(),
                'error_code' => $timeout ? 'AUTOMATED_REPLY_RESULT_UNKNOWN' : 'AUTOMATED_REPLY_FAILED',
                'error_message' => 'Falha ao enviar mensagem automatica.',
            ]);

            $events->record($message->conversation, 'automated_reply_failed', 'Falha ao enviar mensagem automatica.', $message);
            $audit->log('conversation_automation.message_failed', 'Falha ao enviar mensagem automatica.', $message, null, ['conversation_id' => $message->conversation_id]);
        } finally {
            $lock->release();
        }
    }
}
