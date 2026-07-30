<?php

namespace App\Jobs;

use App\Enums\ConversationMessageStatus;
use App\Enums\ConversationStatus;
use App\Models\ConversationMessage;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationEventService;
use App\Services\Conversations\ConversationReplyService;
use App\Services\SystemSettingService;
use App\Services\WhatsApp\WhatsAppProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Envia a resposta ja aprovada ou autorizada, pelo mesmo caminho das demais.
 *
 * Revalida a elegibilidade do contato imediatamente antes de disparar: opt-out
 * ou desativacao entre a aprovacao e o envio cancelam a operacao.
 */
class SendApprovedReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(private readonly int $messageId)
    {
        $this->onQueue(app(SystemSettingService::class)->get('ai.response.send_queue', 'ai-response-send'));
    }

    public function handle(
        WhatsAppProviderManager $providers,
        ConversationEventService $events,
        ConversationReplyService $replies,
        AuditLogger $audit,
    ): void {
        $message = ConversationMessage::with('conversation.contact')->find($this->messageId);

        if (! $message || $message->status === ConversationMessageStatus::Sent) {
            return;
        }

        $eligible = $replies->contactEligible($message->conversation);

        if (! $eligible['allowed']) {
            $message->update([
                'status' => ConversationMessageStatus::Failed,
                'failed_at' => now(),
                'error_code' => 'APPROVED_REPLY_BLOCKED',
                'error_message' => 'Envio bloqueado antes do disparo.',
            ]);
            $events->record($message->conversation, 'ai_reply_blocked', 'Envio de resposta aprovada bloqueado.', $message, null, [
                'reason' => $eligible['reason'],
            ]);

            return;
        }

        $lock = Cache::lock("conversation:{$message->conversation_id}:reply", 120);

        if (! $lock->get()) {
            $this->release(15);

            return;
        }

        try {
            $message->update(['status' => ConversationMessageStatus::Processing]);

            $result = $providers->provider()->sendMessage(
                (string) $message->recipient_phone_snapshot,
                (string) $message->body,
                (string) $message->request_id,
            );

            $message->update([
                'status' => ConversationMessageStatus::Sent,
                'external_message_id' => $result->externalMessageId,
                'sent_at' => $result->sentAt ?? now(),
            ]);

            $message->conversation?->update([
                'status' => ConversationStatus::WaitingContact,
                'last_message_direction' => $message->direction,
                'last_message_at' => now(),
                'last_outgoing_message_at' => now(),
            ]);

            $events->record($message->conversation, 'ai_reply_sent', 'Resposta gerada enviada.', $message);
            $audit->log('conversation_response.sent', 'Resposta gerada enviada.', $message, null, [
                'conversation_id' => $message->conversation_id,
            ]);
        } catch (Throwable $exception) {
            $timeout = str_contains($exception->getMessage(), 'timeout');

            $message->update([
                'status' => $timeout ? ConversationMessageStatus::Unknown : ConversationMessageStatus::Failed,
                'failed_at' => now(),
                'error_code' => $timeout ? 'APPROVED_REPLY_RESULT_UNKNOWN' : 'APPROVED_REPLY_FAILED',
                'error_message' => 'Falha ao enviar resposta gerada.',
            ]);

            $events->record($message->conversation, 'ai_reply_failed', 'Falha ao enviar resposta gerada.', $message);
        } finally {
            $lock->release();
        }
    }
}
