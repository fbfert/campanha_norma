<?php

namespace App\Jobs;

use App\Enums\ContactMatchStatus;
use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageOrigin;
use App\Enums\ConversationMessageStatus;
use App\Enums\ConversationStatus;
use App\Models\ConversationMessage;
use App\Models\MessageBatchRecipient;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationEventService;
use App\Services\Conversations\ConversationResolverService;
use App\Services\Conversations\ReplyInterruptionService;
use App\Services\IncomingMessages\ContactMatcherService;
use App\Services\IncomingMessages\IncomingMessageNormalizerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcessIncomingMessageJob implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly array $payload)
    {
        $this->onQueue(config('whatsapp.incoming.queue', 'whatsapp-incoming'));
    }

    public function handle(
        IncomingMessageNormalizerService $normalizer,
        ContactMatcherService $matcher,
        ConversationResolverService $resolver,
        ReplyInterruptionService $interruption,
        ConversationEventService $events,
        AuditLogger $audit
    ): void {
        try {
            $data = $normalizer->normalize($this->payload);
        } catch (ValidationException) {
            return;
        }

        if ($data['is_group']) {
            return;
        }

        $existing = ConversationMessage::query()
            ->where('provider', $data['provider'])
            ->where('external_message_id', $data['external_message_id'])
            ->orWhere('event_id', $data['event_id'])
            ->first();

        if ($existing) {
            $events->record($existing->conversation, 'incoming_message_duplicate_ignored', 'Mensagem recebida duplicada ignorada.', $existing);

            return;
        }

        $match = $matcher->match((string) $data['sender_phone']);
        $contact = $match['status'] === ContactMatchStatus::Matched ? $match['contact'] : null;
        $direction = $data['is_from_me'] ? ConversationMessageDirection::Outgoing : ConversationMessageDirection::Incoming;
        $status = $data['is_from_me'] ? ConversationMessageStatus::Sent : ConversationMessageStatus::Received;

        DB::transaction(function () use ($data, $contact, $direction, $status, $resolver, $events, $interruption, $audit): void {
            // A avaliação do fluxo e despachada apenas após o commit desta transação,
            // em fila própria, para nunca atrasar o registro da mensagem recebida.
            $conversation = $resolver->resolve($contact, $data['connection_id'], $direction === ConversationMessageDirection::Incoming, (string) $data['sender_phone']);
            $recipient = $this->findInitialRecipient($contact?->id, $data['sender_phone']);

            $message = ConversationMessage::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $contact?->id,
                'message_batch_recipient_id' => $recipient?->id,
                'direction' => $direction,
                'message_type' => $data['message_type'],
                'provider' => $data['provider'],
                'origin' => $direction === ConversationMessageDirection::Incoming ? ConversationMessageOrigin::Incoming : ConversationMessageOrigin::Sync,
                'external_message_id' => $data['external_message_id'],
                'event_id' => $data['event_id'],
                'sender_phone_snapshot' => $data['sender_phone'],
                'recipient_phone_snapshot' => $data['recipient_phone'],
                'sender_name_snapshot' => $data['sender_name'] ?: null,
                'body' => $data['text'],
                'has_media' => $data['has_media'],
                'media_metadata' => $data['metadata'],
                'quoted_message_id' => $data['quoted_external_message_id'],
                'status' => $status,
                'sent_at' => $direction === ConversationMessageDirection::Outgoing ? ($data['sent_at'] ?? $data['received_at']) : null,
                'received_at' => $direction === ConversationMessageDirection::Incoming ? $data['received_at'] : null,
            ]);

            $updates = [
                'last_message_direction' => $direction,
                'last_message_at' => $data['received_at'] ?? now(),
            ];

            if ($direction === ConversationMessageDirection::Incoming) {
                $updates['last_incoming_message_at'] = $data['received_at'] ?? now();
                $updates['unread_count'] = $conversation->unread_count + 1;
                $updates['status'] = ConversationStatus::WaitingOperator;
                $updates['is_archived'] = false;
            } else {
                $updates['last_outgoing_message_at'] = $data['sent_at'] ?? now();
                $updates['status'] = ConversationStatus::WaitingContact;
            }

            $conversation->forceFill($updates)->save();

            if ($direction === ConversationMessageDirection::Incoming && $contact) {
                $contact->forceFill([
                    'has_replied' => true,
                    'first_replied_at' => $contact->first_replied_at ?: ($data['received_at'] ?? now()),
                    'last_replied_at' => $data['received_at'] ?? now(),
                ])->save();

                $interrupted = $interruption->interrupt($contact, $matchPhone = (string) $data['sender_phone']);
                $events->record($conversation, 'incoming_message_received', 'Mensagem recebida registrada.', $message, null, ['interrupted' => $interrupted, 'phone' => $matchPhone]);
                $audit->log('incoming_message.received', 'Mensagem recebida registrada.', $message, null, ['conversation_id' => $conversation->id, 'interrupted' => $interrupted]);
            } else {
                $events->record($conversation, $direction === ConversationMessageDirection::Incoming ? 'incoming_message_received' : 'outgoing_message_detected_externally', 'Mensagem registrada.', $message);
            }

            if (! $contact && $direction === ConversationMessageDirection::Incoming) {
                $events->record($conversation, 'contact_match_failed', 'Contato não identificado.', $message, null, ['phone' => $data['sender_phone']]);
                $audit->log('incoming_message.contact_not_found', 'Mensagem recebida sem contato identificado.', $message, null, ['conversation_id' => $conversation->id]);
            }

            if ($this->shouldEvaluateFlow($direction, $message)) {
                DB::afterCommit(fn () => EvaluateConversationFlowJob::dispatch($message->id));
            }
        });
    }

    /**
     * Somente mensagens recebidas de texto entram na avaliação do fluxo.
     */
    private function shouldEvaluateFlow(ConversationMessageDirection $direction, ConversationMessage $message): bool
    {
        return $direction === ConversationMessageDirection::Incoming
            && $message->message_type === 'text'
            && filled($message->body);
    }

    private function findInitialRecipient(?int $contactId, ?string $phone): ?MessageBatchRecipient
    {
        return MessageBatchRecipient::query()
            ->where(function ($query) use ($contactId, $phone): void {
                if ($contactId) {
                    $query->orWhere('contact_id', $contactId);
                }
                if ($phone) {
                    $query->orWhere('contact_phone_snapshot', $phone);
                }
            })
            ->where('processing_status', 'sent')
            ->latest('sent_at')
            ->first();
    }
}
