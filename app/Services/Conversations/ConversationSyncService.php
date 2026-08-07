<?php

namespace App\Services\Conversations;

use App\Enums\ContactMatchStatus;
use App\Enums\ConversationMessageDirection;
use App\Enums\ConversationMessageStatus;
use App\Enums\ConversationPriority;
use App\Enums\ConversationStatus;
use App\Enums\ConversationSyncStatus;
use App\Exceptions\WhatsApp\WhatsAppServiceException;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationSyncRun;
use App\Services\AuditLogger;
use App\Services\IncomingMessages\ContactMatcherService;
use App\Services\SystemSettingService;
use App\Services\WhatsApp\WhatsAppProviderManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ConversationSyncService
{
    private const MAX_CHATS = 500;

    private const MAX_MESSAGES_PER_CHAT = 500;

    public function __construct(
        private readonly WhatsAppProviderManager $providers,
        private readonly ContactMatcherService $matcher,
        private readonly ConversationEventService $events,
        private readonly ReplyInterruptionService $interruption,
        private readonly SystemSettingService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function run(ConversationSyncRun $run, array $options = []): ConversationSyncRun
    {
        $options = $this->sanitizeOptions($options);
        $run->forceFill([
            'status' => ConversationSyncStatus::Running,
            'started_at' => now(),
            'last_heartbeat_at' => now(),
            'options' => $options,
        ])->save();

        try {
            $chats = $this->providers->provider()->listConversations([
                'limit' => $options['limit_chats'],
                'include_archived' => $options['include_archived'] ? '1' : '0',
            ]);

            $chatItems = collect($chats['conversations'] ?? $chats)->filter(fn ($chat): bool => is_array($chat));
            $syncMode = (string) ($chats['sync_mode'] ?? 'standard');
            $run->forceFill([
                'chats_found' => $chatItems->count(),
                'options' => array_merge($options, [
                    'sync_mode' => $syncMode,
                    'normal_mode_ok' => (bool) ($chats['normal_mode_ok'] ?? false),
                    'fallback_mode_ok' => (bool) ($chats['fallback_mode_ok'] ?? false),
                ]),
            ])->save();

            foreach ($chatItems as $chat) {
                try {
                    $this->syncChat($run, $chat, $options);
                } catch (\Throwable $exception) {
                    $this->recordChatFailure($run, $chat, $exception);
                }
            }

            $status = $this->resolveFinalStatus($run);
            $errorMessage = $status === ConversationSyncStatus::CompletedWithErrors && filled($run->error_message)
                ? $run->error_message
                : null;

            $run->forceFill([
                'status' => $status,
                'finished_at' => now(),
                'last_heartbeat_at' => now(),
                'error_code' => $status === ConversationSyncStatus::Completed ? null : $run->error_code,
                'error_message' => $errorMessage,
            ])->save();

            $this->audit->log('conversation.sync_completed', 'Sincronização de conversas concluída.', $run, null, $run->only(['chats_processed', 'chats_failed', 'messages_imported', 'messages_skipped', 'messages_failed', 'options']));
        } catch (\Throwable $exception) {
            $errorCode = $exception instanceof WhatsAppServiceException ? $exception->errorCode : 'CONVERSATION_SYNC_FAILED';
            $errorMessage = match ($errorCode) {
                'INTERNAL_ERROR' => 'O serviço WhatsApp não conseguiu listar os chats da sessão atual. Verifique a compatibilidade do WhatsApp Web e os logs do Node.js.',
                'WHATSAPP_GET_CHATS_FAILED' => 'A consulta padrão dos chats falhou. O sistema tentou o modo de compatibilidade, mas não conseguiu acessar as conversas disponíveis nesta sessão.',
                'WHATSAPP_CHAT_COLLECTION_UNAVAILABLE' => 'A consulta padrão dos chats falhou. O sistema tentou o modo de compatibilidade, mas não conseguiu acessar as conversas disponíveis nesta sessão.',
                'WHATSAPP_FALLBACK_FAILED' => 'A consulta padrão dos chats falhou. O sistema tentou o modo de compatibilidade, mas não conseguiu acessar as conversas disponíveis nesta sessão.',
                'WHATSAPP_NOT_CONNECTED' => 'Conecte o WhatsApp antes de sincronizar as conversas.',
                'SERVICE_UNAVAILABLE' => 'O serviço Node.js do WhatsApp esta indisponível.',
                'SERVICE_TIMEOUT' => 'O serviço do WhatsApp não respondeu a tempo. Ele costuma estar de pé e travado: reinicie o serviço do Node.js.',
                default => 'Falha ao sincronizar conversas.',
            };

            $run->forceFill([
                'status' => ConversationSyncStatus::Failed,
                'finished_at' => now(),
                'last_heartbeat_at' => now(),
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ])->save();

            $this->audit->log('conversation.sync_failed', 'Falha na sincronização de conversas.', $run, null, ['error_code' => $run->error_code]);
        }

        return $run->fresh();
    }

    public function sanitizeOptions(array $options): array
    {
        return [
            'chat' => filled($options['chat'] ?? null) ? (string) $options['chat'] : null,
            'days' => max(1, min(365, (int) ($options['days'] ?? $this->settings->get('conversations.sync_days_back', 30)))),
            'limit_chats' => max(1, min(self::MAX_CHATS, (int) ($options['limit_chats'] ?? $this->settings->get('conversations.sync_max_chats', 100)))),
            'messages_per_chat' => max(1, min(self::MAX_MESSAGES_PER_CHAT, (int) ($options['messages_per_chat'] ?? $this->settings->get('conversations.sync_messages_per_chat', 50)))),
            'include_archived' => filter_var($options['include_archived'] ?? $this->settings->get('conversations.sync_include_archived', false), FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function syncChat(ConversationSyncRun $run, array $chat, array $options): void
    {
        if (($chat['is_group'] ?? false) || blank($chat['external_chat_id'] ?? null)) {
            return;
        }

        if ($options['chat'] && $options['chat'] !== $chat['external_chat_id']) {
            return;
        }

        $match = $this->matcher->match((string) ($chat['phone'] ?? ''));
        $contact = $match['status'] === ContactMatchStatus::Matched ? $match['contact'] : null;

        $messages = $this->providers->provider()->fetchConversationMessages((string) $chat['external_chat_id'], [
            'limit' => $options['messages_per_chat'],
            'days' => $options['days'],
        ]);

        $messageItems = collect($messages['messages'] ?? $messages)->filter(fn ($message): bool => is_array($message));
        $run->increment('messages_found', $messageItems->count());

        $conversation = DB::transaction(function () use ($chat, $contact): ?Conversation {
            $externalChatId = (string) $chat['external_chat_id'];
            $conversation = Conversation::query()
                ->where('provider', 'web')
                ->where('external_chat_id', $externalChatId)
                ->first();

            if (! $conversation && Conversation::onlyTrashed()->where('provider', 'web')->where('external_chat_id', $externalChatId)->exists()) {
                // Conversa foi removida intencionalmente (ex.: limpeza de conversas vazias
                // sem contato/mensagem). Não recriar automaticamente via sincronização -
                // isso colidiria com a restrição única de provider+external_chat_id.
                return null;
            }

            if (! $conversation && $contact) {
                $conversation = Conversation::query()
                    ->where('connection_id', 'principal')
                    ->where('contact_id', $contact->id)
                    ->whereNull('external_chat_id')
                    ->latest('last_message_at')
                    ->first();
            }

            if ($conversation) {
                $conversation->forceFill([
                    'provider' => 'web',
                    'external_chat_id' => $externalChatId,
                    'contact_id' => $conversation->contact_id ?: $contact?->id,
                ])->save();

                return $conversation;
            }

            return Conversation::create([
                'contact_id' => $contact?->id,
                'connection_id' => 'principal',
                'provider' => 'web',
                'external_chat_id' => $externalChatId,
                'status' => ConversationStatus::Open,
                'priority' => ConversationPriority::Normal,
                'last_message_at' => $this->date($chat['last_message_at'] ?? null) ?? now(),
                'unread_count' => 0,
            ]);
        });

        if (! $conversation) {
            return;
        }

        if (! $conversation->contact_id && $contact) {
            $conversation->forceFill(['contact_id' => $contact->id])->save();
        }

        foreach ($messageItems as $message) {
            $this->syncMessage($run, $conversation, $message, $chat, $contact);
        }

        $lastMessageAt = $conversation->messages()
            ->selectRaw('COALESCE(sent_at, received_at, created_at) as activity_at')
            ->orderByDesc('activity_at')
            ->value('activity_at');

        $conversation->forceFill([
            'last_synced_at' => now(),
            'last_message_at' => $lastMessageAt ? Carbon::parse($lastMessageAt) : $conversation->last_message_at,
            'unread_count' => max((int) ($chat['unread_count'] ?? 0), (int) $conversation->unread_count),
        ])->save();

        $this->events->record($conversation, 'synced', 'Conversa sincronizada pelo WhatsApp Web.', null, null, [
            'external_chat_id' => $chat['external_chat_id'],
            'messages' => $messageItems->count(),
        ]);
        $run->increment('chats_processed');
        $run->forceFill(['last_heartbeat_at' => now()])->save();
    }

    /**
     * Tipos que o WhatsApp gera sozinho e que nenhuma pessoa escreveu.
     *
     * O aviso de troca de chave de criptografia entrava como mensagem recebida,
     * de corpo vazio: a automação lia aquilo como se a pessoa tivesse
     * respondido, não entendia nada e encaminhava a conversa para atendimento
     * humano. Uma respondente que apenas ainda não tinha respondido aparecia
     * como parada, esperando gente.
     */
    public const PROTOCOL_TYPES = [
        'e2e_notification',
        'notification_template',
        'call_log',
        'gp2',
        'protocol',
        'revoked',
        'ciphertext',
    ];


    private function syncMessage(ConversationSyncRun $run, Conversation $conversation, array $message, array $chat, mixed $contact): void
    {
        if (blank($message['external_message_id'] ?? null)) {
            $run->increment('messages_failed');

            return;
        }

        if (in_array((string) ($message['type'] ?? ''), self::PROTOCOL_TYPES, true)) {
            $run->increment('messages_skipped');

            return;
        }

        $exists = ConversationMessage::query()
            ->where('provider', 'web')
            ->where('external_message_id', (string) $message['external_message_id'])
            ->exists();

        if ($exists) {
            $run->increment('messages_skipped');

            return;
        }

        $direction = ($message['direction'] ?? 'incoming') === 'outgoing' ? ConversationMessageDirection::Outgoing : ConversationMessageDirection::Incoming;
        $occurredAt = $this->date($message['sent_at'] ?? null) ?? now();

        if ($direction === ConversationMessageDirection::Outgoing
            && $this->adoptOwnMessage($conversation, $message, $occurredAt)) {
            $run->increment('messages_skipped');

            return;
        }

        DB::transaction(function () use ($conversation, $message, $chat, $contact, $direction, $occurredAt, $run): void {
            $record = ConversationMessage::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $contact?->id,
                'direction' => $direction,
                'message_type' => (string) ($message['type'] ?? 'unknown'),
                'provider' => 'web',
                'external_chat_id' => (string) ($message['external_chat_id'] ?? $chat['external_chat_id']),
                'external_message_id' => (string) $message['external_message_id'],
                'sender_phone_snapshot' => $direction === ConversationMessageDirection::Incoming ? ($chat['phone'] ?? null) : null,
                'recipient_phone_snapshot' => $direction === ConversationMessageDirection::Outgoing ? ($chat['phone'] ?? null) : null,
                'sender_name_snapshot' => $chat['name'] ?? null,
                'body' => $message['body'] ?? null,
                'has_media' => (bool) ($message['has_media'] ?? false),
                'media_metadata' => $message['metadata'] ?? [],
                'status' => $direction === ConversationMessageDirection::Incoming ? ConversationMessageStatus::Received : ConversationMessageStatus::Sent,
                'sent_at' => $direction === ConversationMessageDirection::Outgoing ? $occurredAt : null,
                'received_at' => $direction === ConversationMessageDirection::Incoming ? $occurredAt : null,
                'read_at' => $direction === ConversationMessageDirection::Incoming && (int) ($chat['unread_count'] ?? 0) === 0 ? now() : null,
            ]);

            $conversation->forceFill([
                'last_message_direction' => $direction,
                'last_message_at' => $occurredAt,
                'last_incoming_message_at' => $direction === ConversationMessageDirection::Incoming ? $occurredAt : $conversation->last_incoming_message_at,
                'last_outgoing_message_at' => $direction === ConversationMessageDirection::Outgoing ? $occurredAt : $conversation->last_outgoing_message_at,
                'status' => $direction === ConversationMessageDirection::Incoming ? ConversationStatus::WaitingOperator : ConversationStatus::WaitingContact,
                'is_archived' => false,
            ])->save();

            if ($direction === ConversationMessageDirection::Incoming && $contact) {
                $contact->forceFill([
                    'has_replied' => true,
                    'first_replied_at' => $contact->first_replied_at ?: $occurredAt,
                    'last_replied_at' => $occurredAt,
                ])->save();
                $this->interruption->interrupt($contact, (string) ($chat['phone'] ?? ''));
            }

            $this->events->record($conversation, $direction === ConversationMessageDirection::Incoming ? 'incoming_message_synced' : 'outgoing_message_synced', 'Mensagem sincronizada pelo WhatsApp Web.', $record);
            $run->increment('messages_imported');
        });
    }

    private function recordChatFailure(ConversationSyncRun $run, array $chat, \Throwable $exception): void
    {
        $run->increment('chats_failed');
        $errorCode = $exception instanceof WhatsAppServiceException ? $exception->errorCode : 'CONVERSATION_SYNC_CHAT_FAILED';
        $message = $exception instanceof WhatsAppServiceException
            ? $exception->getMessage()
            : 'Falha ao processar um chat da sincronização.';

        if (! $exception instanceof WhatsAppServiceException) {
            report($exception);
        }

        $this->audit->log('conversation.sync_chat_failed', 'Falha em um chat durante a sincronização.', $run, null, [
            'error_code' => $errorCode,
            'chat_hash' => hash('sha256', (string) ($chat['external_chat_id'] ?? '')),
        ]);

        if (blank($run->error_code)) {
            $run->forceFill([
                'error_code' => $errorCode,
                'error_message' => $message,
            ])->save();
        }
    }

    private function resolveFinalStatus(ConversationSyncRun $run): ConversationSyncStatus
    {
        if ((int) $run->chats_processed === 0 && (int) $run->chats_failed > 0) {
            return ConversationSyncStatus::Failed;
        }

        if ((int) $run->chats_failed > 0 || (int) $run->messages_failed > 0) {
            return ConversationSyncStatus::CompletedWithErrors;
        }

        return ConversationSyncStatus::Completed;
    }

    /**
     * O eco entra por duas portas — esta e o webhook ao vivo — e a regra mora
     * em `OutgoingEchoMatcher`, para ser consertada uma vez so.
     */
    private function adoptOwnMessage(Conversation $conversation, array $message, Carbon $occurredAt): bool
    {
        return app(OutgoingEchoMatcher::class)->adopt(
            $conversation,
            $message['body'] ?? null,
            isset($message['external_message_id']) ? (string) $message['external_message_id'] : null,
            $message['external_chat_id'] ?? null,
            $occurredAt,
        );
    }

    private function date(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            // Convertido para o fuso da aplicação: o WhatsApp Web entrega
            // ISO-8601 em UTC, e guardar assim faria a linha do tempo mostrar
            // o evento três horas adiante do que ele aconteceu.
            return Carbon::parse((string) $value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }
}
