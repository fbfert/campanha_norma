<?php

namespace App\Services\Conversations;

use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Models\Contact;
use App\Models\Conversation;
use App\Services\SystemSettingService;

class ConversationResolverService
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function resolve(?Contact $contact, ?string $connectionId, bool $incoming = true, ?string $senderPhone = null): Conversation
    {
        // Arquivada continua sendo a conversa da pessoa. Ela era excluída da
        // busca, e o resultado era uma segunda conversa toda vez que alguém
        // voltava a escrever depois do arquivamento automático — que roda
        // diariamente. O trecho abaixo já desarquiva o que encontra.
        //
        // Encerrada e outra coisa: fechar foi decisão de quem operou, e reabrir
        // por conta própria desfaria essa decisão. Ali uma conversa nova e o
        // comportamento certo.
        $query = Conversation::query()
            ->where('connection_id', $connectionId)
            ->whereNotIn('status', [ConversationStatus::Closed->value]);

        if ($contact) {
            $query->where('contact_id', $contact->id);
        } else {
            $query->whereNull('contact_id');

            // Sem contato identificado, o telefone informado (bruto ou resolvido
            // via lid) e o único jeito de não misturar remetentes diferentes na
            // mesma conversa "sem contato".
            if (filled($senderPhone)) {
                $query->whereHas('messages', function ($messages) use ($senderPhone): void {
                    $messages->where('sender_phone_snapshot', $senderPhone)
                        ->orWhere('recipient_phone_snapshot', $senderPhone);
                });
            } else {
                $query->whereDoesntHave('messages');
            }
        }

        $conversation = $query->latest('last_message_at')->first();

        if (! $conversation) {
            return Conversation::create([
                'contact_id' => $contact?->id,
                'connection_id' => $connectionId,
                'status' => $incoming ? ConversationStatus::New : ConversationStatus::Open,
                'priority' => ConversationPriority::Normal,
                'last_message_direction' => $incoming ? ConversationMessageDirection::Incoming : ConversationMessageDirection::Outgoing,
                'last_message_at' => now(),
                'unread_count' => 0,
            ]);
        }

        if ($incoming) {
            $conversation->forceFill([
                'status' => ConversationStatus::tryFrom((string) $this->settings->get('inbox.default_status_after_incoming', 'waiting_operator')) ?? ConversationStatus::WaitingOperator,
                'is_archived' => false,
                'archived_at' => null,
                'archived_by' => null,
            ])->save();
        } elseif ($conversation->is_archived) {
            // Saída para conversa arquivada também a traz de volta: mandar
            // mensagem e retomar o assunto, e deixa-la arquivada esconderia da
            // caixa justamente a conversa que acabou de receber algo.
            $conversation->forceFill([
                'is_archived' => false,
                'archived_at' => null,
                'archived_by' => null,
            ])->save();
        }

        return $conversation;
    }
}
