<?php

namespace App\Services\ConversationAutomation;

use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageOrigin;
use App\Enums\ConversationMessageStatus;
use App\Jobs\SendAutomatedConversationReplyJob;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationEventService;
use App\Services\SystemSettingService;
use Illuminate\Support\Str;

/**
 * Cria a mensagem automatica pendente e enfileira o envio.
 * Nunca chama o provedor diretamente: o envio e responsabilidade do job.
 */
class ConversationAutomatedReplyService
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly ConversationEventService $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function queue(ConversationFlowState $state, string $body, string $eventType, array $metadata = []): ?ConversationMessage
    {
        $conversation = $state->conversation;
        $contact = $conversation?->contact;

        if (! $conversation || ! $contact) {
            return null;
        }

        $body = trim($this->applyTransparency($state->flow, $body));

        if ($body === '') {
            return null;
        }

        $message = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'direction' => ConversationMessageDirection::Outgoing,
            'message_type' => 'text',
            'provider' => config('whatsapp.provider', 'web'),
            'origin' => ConversationMessageOrigin::Automation,
            'request_id' => (string) Str::uuid(),
            'sender_phone_snapshot' => null,
            'recipient_phone_snapshot' => $contact->phone_normalized,
            'sender_name_snapshot' => null,
            'body' => $body,
            'status' => ConversationMessageStatus::Pending,
            'created_by' => null,
        ]);

        $state->forceFill([
            'automated_messages_count' => $state->automated_messages_count + 1,
            'last_automated_message_id' => $message->id,
        ])->save();

        $this->events->record($conversation, $eventType, 'Mensagem automatica enfileirada.', $message, null, $metadata + [
            'flow_id' => $state->conversation_flow_id,
            'automated' => true,
        ]);

        $this->audit->log('conversation_automation.message_queued', 'Mensagem automatica enfileirada.', $message, null, [
            'conversation_id' => $conversation->id,
            'flow_id' => $state->conversation_flow_id,
            'event_type' => $eventType,
        ]);

        SendAutomatedConversationReplyJob::dispatch($message->id)->onQueue($this->sendQueue());

        return $message;
    }

    /**
     * Aviso de atendimento automatizado, exigencia de transparencia.
     */
    public function applyTransparency(?ConversationFlow $flow, string $body): string
    {
        if (! $flow || ! $flow->transparency_enabled) {
            return $body;
        }

        $text = trim((string) ($flow->transparency_text ?: $this->settings->get('conversation_automation.transparency_text', '')));

        if ($text === '') {
            return $body;
        }

        $mode = (string) $this->settings->get('conversation_automation.transparency_mode', 'suffix');

        return match ($mode) {
            'none' => $body,
            'prefix' => $text."\n\n".$body,
            default => $body."\n\n".$text,
        };
    }

    public function sendQueue(): string
    {
        return (string) $this->settings->get('conversation_automation.send_queue', 'conversation-automation-send');
    }
}
