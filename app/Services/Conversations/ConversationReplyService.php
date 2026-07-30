<?php

namespace App\Services\Conversations;

use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageOrigin;
use App\Enums\ConversationMessageStatus;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Str;

/**
 * Serviço de saída unificado.
 *
 * Concentra o que e comum e perigoso em qualquer envio: elegibilidade do
 * contato, criação da mensagem pendente, identificador de requisição único,
 * snapshots, evento e auditoria. O despacho para a fila continua a cargo de
 * quem chama, porque manual, automático e aprovado usam filas diferentes.
 *
 * Não contem regra de negócio específica de nenhuma origem: as validações
 * próprias do envio manual continuam em `ManualReplyService`, e as da automação
 * em `ConversationAutomationGuard`.
 */
class ConversationReplyService
{
    public function __construct(
        private readonly ConversationEventService $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Elegibilidade mínima comum a qualquer envio pelo sistema.
     *
     * @return array{allowed: bool, reason: ?string}
     */
    public function contactEligible(?Conversation $conversation): array
    {
        $contact = $conversation?->contact;

        if (! $contact) {
            return ['allowed' => false, 'reason' => 'contato_nao_associado'];
        }

        if ($contact->do_not_contact) {
            return ['allowed' => false, 'reason' => 'contato_nao_contatar'];
        }

        if ($contact->status->value !== 'active') {
            return ['allowed' => false, 'reason' => 'contato_inativo'];
        }

        if (blank($contact->phone_normalized)) {
            return ['allowed' => false, 'reason' => 'contato_sem_telefone'];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Cria a mensagem de saída pendente. Nunca chama o provedor.
     *
     * @param  array<string, mixed>  $metadata  Metadados de autoria de IA.
     * @param  array<string, mixed>  $eventPayload  Dados extras do evento registrado.
     */
    public function createPending(
        Conversation $conversation,
        string $body,
        ConversationMessageOrigin $origin,
        ?User $user = null,
        array $metadata = [],
        ?string $eventType = null,
        ?string $eventDescription = null,
        array $eventPayload = [],
        ?string $auditAction = null,
        ?string $auditDescription = null,
    ): ConversationMessage {
        $contact = $conversation->contact;

        $message = ConversationMessage::create(array_merge([
            'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id,
            'direction' => ConversationMessageDirection::Outgoing,
            'message_type' => 'text',
            'provider' => config('whatsapp.provider', 'web'),
            'origin' => $origin,
            'request_id' => (string) Str::uuid(),
            'sender_phone_snapshot' => null,
            'recipient_phone_snapshot' => $contact?->phone_normalized,
            'sender_name_snapshot' => $user?->name,
            'body' => $body,
            'status' => ConversationMessageStatus::Pending,
            'created_by' => $user?->id,
        ], $metadata));

        if ($eventType !== null) {
            $this->events->record(
                $conversation,
                $eventType,
                $eventDescription,
                $message,
                $user,
                $eventPayload === [] ? null : $eventPayload,
            );
        }

        if ($auditAction !== null) {
            $this->audit->log(
                $auditAction,
                $auditDescription,
                $message,
                null,
                ['conversation_id' => $conversation->id] + $eventPayload,
                $user,
            );
        }

        return $message;
    }
}
