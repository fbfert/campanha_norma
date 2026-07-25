<?php

namespace App\Jobs;

use App\Enums\ConversationMessageStatus;
use App\Enums\ConversationStatus;
use App\Models\ConversationMessage;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationEventService;
use App\Services\WhatsApp\WhatsAppProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class SendManualConversationReplyJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly int $messageId)
    {
        $this->onQueue('whatsapp-manual-replies');
    }

    public function handle(WhatsAppProviderManager $providers, ConversationEventService $events, AuditLogger $audit): void
    {
        $message = ConversationMessage::with('conversation.contact')->find($this->messageId);
        if (! $message || $message->status === ConversationMessageStatus::Sent) {
            return;
        }

        $lock = Cache::lock("conversation:{$message->conversation_id}:reply", 120);
        if (! $lock->get()) {
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
                'first_response_at' => $message->conversation->first_response_at ?: now(),
            ]);

            $events->record($message->conversation, 'reply_sent', 'Resposta manual enviada.', $message, $message->creator);
            $audit->log('conversation.manual_reply_sent', 'Resposta manual enviada.', $message, null, ['conversation_id' => $message->conversation_id]);
        } catch (\Throwable $exception) {
            $message->update([
                'status' => str_contains($exception->getMessage(), 'timeout') ? ConversationMessageStatus::Unknown : ConversationMessageStatus::Failed,
                'failed_at' => now(),
                'error_code' => str_contains($exception->getMessage(), 'timeout') ? 'MANUAL_REPLY_RESULT_UNKNOWN' : 'MANUAL_REPLY_FAILED',
                'error_message' => 'Falha ao enviar resposta manual.',
            ]);
            $events->record($message->conversation, 'reply_failed', 'Falha ao enviar resposta manual.', $message, $message->creator);
            $audit->log('conversation.manual_reply_failed', 'Falha ao enviar resposta manual.', $message, null, ['conversation_id' => $message->conversation_id]);
        } finally {
            $lock->release();
        }
    }
}
