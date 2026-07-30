<?php

namespace App\Services\ConversationAutomation;

use App\Enums\ConversationMessageOrigin;
use App\Jobs\SendAutomatedConversationReplyJob;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Services\Conversations\ConversationReplyService;
use App\Services\SystemSettingService;

/**
 * Cria a mensagem automatica pendente e enfileira o envio.
 * Nunca chama o provedor diretamente: o envio e responsabilidade do job.
 */
class ConversationAutomatedReplyService
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly ConversationReplyService $replies,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata  Dados do evento registrado.
     * @param  array<string, mixed>  $aiMetadata  Metadados de autoria de IA na mensagem.
     */
    public function queue(ConversationFlowState $state, string $body, string $eventType, array $metadata = [], array $aiMetadata = []): ?ConversationMessage
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

        // Criacao pelo servico de saida compartilhado, preservando origem,
        // evento e auditoria proprios da automacao.
        $message = $this->replies->createPending(
            conversation: $conversation,
            body: $body,
            origin: ConversationMessageOrigin::Automation,
            metadata: $aiMetadata,
            eventType: $eventType,
            eventDescription: 'Mensagem automatica enfileirada.',
            eventPayload: $metadata + [
                'flow_id' => $state->conversation_flow_id,
                'automated' => true,
            ],
            auditAction: 'conversation_automation.message_queued',
            auditDescription: 'Mensagem automatica enfileirada.',
        );

        $state->forceFill([
            'automated_messages_count' => $state->automated_messages_count + 1,
            'last_automated_message_id' => $message->id,
        ])->save();

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
