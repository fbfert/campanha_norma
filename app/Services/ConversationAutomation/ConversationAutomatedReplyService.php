<?php

namespace App\Services\ConversationAutomation;

use App\Enums\ConversationMessageOrigin;
use App\Jobs\SendAutomatedConversationReplyJob;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use App\Services\Conversations\ConversationEventService;
use App\Services\Conversations\ConversationReplyService;
use App\Services\Placeholders\MessageRendererService;
use App\Services\SystemSettingService;

/**
 * Cria a mensagem automática pendente e enfileira o envio.
 * Nunca chama o provedor diretamente: o envio e responsabilidade do job.
 */
class ConversationAutomatedReplyService
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly ConversationReplyService $replies,
        private readonly MessageRendererService $renderer,
        private readonly ConversationEventService $events,
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

        // Placeholders são resolvidos aqui, antes do aviso de transparência:
        // o aviso e texto fixo do sistema e não personaliza ninguém.
        $render = $this->renderer->render($body, $contact);

        if ($render['missing'] !== []) {
            // Enviar "{cidade}" literal para um cidadão e pior que não enviar.
            // Quem escreveu a pergunta escolheu um campo que este contato não
            // tem, e isso e problema de gente, não de automação.
            $this->events->record($conversation, 'automation_placeholder_missing', 'Mensagem automática não enviada: campo do contato vazio.', null, null, [
                'flow_id' => $state->conversation_flow_id,
                'missing' => $render['missing'],
            ]);

            return null;
        }

        $body = trim($this->applyTransparency($state->flow, $render['message']));

        if ($body === '') {
            return null;
        }

        // Criação pelo serviço de saída compartilhado, preservando origem,
        // evento e auditoria próprios da automação.
        $message = $this->replies->createPending(
            conversation: $conversation,
            body: $body,
            origin: ConversationMessageOrigin::Automation,
            metadata: $aiMetadata,
            eventType: $eventType,
            eventDescription: 'Mensagem automática enfileirada.',
            eventPayload: $metadata + [
                'flow_id' => $state->conversation_flow_id,
                'automated' => true,
            ],
            auditAction: 'conversation_automation.message_queued',
            auditDescription: 'Mensagem automática enfileirada.',
        );

        $state->forceFill([
            'automated_messages_count' => $state->automated_messages_count + 1,
            'last_automated_message_id' => $message->id,
        ])->save();

        SendAutomatedConversationReplyJob::dispatch($message->id)->onQueue($this->sendQueue());

        return $message;
    }

    /**
     * Aviso de atendimento automatizado, exigência de transparência.
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
