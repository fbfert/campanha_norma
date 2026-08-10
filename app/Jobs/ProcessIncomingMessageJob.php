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
use App\Services\Conversations\ConversationSyncService;
use App\Services\ConversationAutomation\UnreadableMediaResponder;
use App\Services\Conversations\OutgoingEchoMatcher;
use App\Services\Conversations\ReplyInterruptionService;
use App\Services\IncomingMessages\ContactMatcherService;
use App\Services\IncomingMessages\IncomingMessageNormalizerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        } catch (ValidationException $excecao) {
            /*
             | Payload recusado saía daqui sem deixar rastro, e foi isso que
             | escondeu por dias que toda nota de voz era descartada na
             | validação: nenhum log, nenhum evento, nenhuma contagem. Do lado
             | de fora era indistinguível de ninguém ter mandado nada.
             |
             | O conteúdo da mensagem não entra no registro — só o que permite
             | reconhecer o padrão e o identificador para achar o original.
             */
            Log::warning('incoming_message.rejected', [
                'external_message_id' => $this->payload['external_message_id'] ?? null,
                'message_type' => $this->payload['message_type'] ?? null,
                'errors' => array_keys($excecao->validator->errors()->toArray()),
            ]);

            return;
        }

        if ($data['is_group']) {
            return;
        }

        // Aviso que o próprio WhatsApp gera — troca de chave, chamada, evento de
        // grupo — não e mensagem de ninguém. Registrar como recebida faz a
        // automação tratar aquilo como resposta da pessoa.
        if (in_array((string) $data['message_type'], ConversationSyncService::PROTOCOL_TYPES, true)) {
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

        /*
         | Eco da nossa própria mensagem, chegando de volta pelo webhook.
         |
         | O WhatsApp Web às vezes entrega e ainda assim lança erro, sem
         | devolver o identificador: a linha do envio fica sem `external_message_id`,
         | a checagem de duplicidade acima compara identificadores, não acha
         | nada, e cria uma segunda linha. A mesma frase aparecia duas vezes na
         | tela e entrava duas vezes no contexto enviado ao modelo — ainda que o
         | contato tivesse recebido uma só.
         |
         | Esta adoção já existia na sincronização periódica, e faltava aqui. É
         | a mesma regra, e agora mora num lugar só.
         */
        if ($direction === ConversationMessageDirection::Outgoing) {
            $conversaExistente = $resolver->resolve($contact, $data['connection_id'], false, (string) $data['sender_phone']);

            $adotada = app(OutgoingEchoMatcher::class)->adopt(
                $conversaExistente,
                $data['text'],
                (string) $data['external_message_id'],
                $data['metadata']['external_chat_id'] ?? null,
                $data['sent_at'] ?? $data['received_at'] ?? now(),
            );

            if ($adotada) {
                return;
            }
        }

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
            } elseif ($this->shouldTranscribe($direction, $message)) {
                // Áudio não tem texto para o motor avaliar. A transcrição corre
                // primeiro e, dando certo, ela mesma devolve a mensagem ao fluxo.
                DB::afterCommit(fn () => TranscribeIncomingAudioJob::dispatch($message->id));
            } elseif (app(UnreadableMediaResponder::class)->handles($message)) {
                /*
                 | Figurinha, imagem, vídeo e documento não caíam em lugar
                 | nenhum: o motor só avalia `text` e a transcrição só trata
                 | áudio. O resultado era silêncio absoluto — uma figurinha
                 | ficou dois dias sem retorno, e a conversa só voltou porque a
                 | pessoa escreveu de novo por conta própria.
                 |
                 | Isto não lê a mídia. Diz que chegou e que o caminho é
                 | escrever, que é o mínimo que se deve a quem mandou.
                 */
                DB::afterCommit(fn () => app(UnreadableMediaResponder::class)
                    ->askForText($message, 'conversation_automation.media_reply_text'));
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

    /**
     * Nota de voz recebida.
     *
     * `ptt` e o áudio gravado na hora, `audio` e o arquivo anexado. Os dois
     * chegam com corpo vazio e, sem transcrição, ficam invisíveis para o motor.
     */
    private function shouldTranscribe(ConversationMessageDirection $direction, ConversationMessage $message): bool
    {
        return $direction === ConversationMessageDirection::Incoming
            && in_array($message->message_type, ['ptt', 'audio'], true)
            && $message->has_media;
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
