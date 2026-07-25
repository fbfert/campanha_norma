<?php

namespace App\Services\WhatsApp;

use App\Enums\ContactStatus;
use App\Enums\WhatsAppConnectionStatus;
use App\Enums\WhatsAppTestMessageStatus;
use App\Exceptions\WhatsApp\WhatsAppServiceException;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsAppTestMessage;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WhatsAppTestMessageService
{
    public function __construct(
        private readonly WhatsAppProviderManager $manager,
        private readonly WhatsAppConnectionService $connections,
        private readonly AuditLogger $audit,
    ) {}

    public function send(Contact $contact, User $user, string $message, Request $request): WhatsAppTestMessage
    {
        if (! (bool) config('whatsapp.test_message_enabled')) {
            throw ValidationException::withMessages(['message' => 'O envio de mensagem de teste esta desativado.']);
        }

        $this->validateContact($contact);

        $status = $this->connections->refreshStatus($user);
        if ($status->status !== WhatsAppConnectionStatus::Connected) {
            throw ValidationException::withMessages(['connection' => 'A conta do WhatsApp precisa estar conectada para enviar teste.']);
        }

        $requestId = (string) Str::uuid();

        $testMessage = WhatsAppTestMessage::create([
            'contact_id' => $contact->id,
            'user_id' => $user->id,
            'request_id' => $requestId,
            'phone_snapshot' => (string) $contact->phone_normalized,
            'message' => $message,
            'status' => WhatsAppTestMessageStatus::Processing,
        ]);

        $this->audit->log('whatsapp.test_message_requested', 'Mensagem individual de teste solicitada.', $testMessage, null, [
            'contact_id' => $contact->id,
            'request_id' => $requestId,
        ], $user, $request);

        try {
            $result = $this->manager->provider()->sendTestMessage((string) $contact->phone_normalized, $message, $requestId);

            if ($result->status !== 'sent') {
                throw new WhatsAppServiceException(
                    $result->errorCode ?? 'SEND_FAILED',
                    $result->errorMessage ?? 'Falha no envio da mensagem de teste.'
                );
            }

            $testMessage->forceFill([
                'status' => WhatsAppTestMessageStatus::Sent,
                'external_message_id' => $result->externalMessageId,
                'sent_at' => $result->sentAt ?? now(),
            ])->save();

            $connection = $this->connections->connection($user);
            $this->connections->event('test_message_sent', $connection, $user, $request, $connection->status, 'Mensagem individual de teste enviada.', null, null, ['request_id' => $requestId]);
            $this->audit->log('whatsapp.test_message_sent', 'Mensagem individual de teste enviada.', $testMessage, null, ['request_id' => $requestId], $user, $request);
        } catch (WhatsAppServiceException $exception) {
            $testMessage->forceFill([
                'status' => WhatsAppTestMessageStatus::Failed,
                'failed_at' => now(),
                'error_code' => $exception->errorCode,
                'error_message' => $exception->userMessage(),
            ])->save();

            $connection = $this->connections->connection($user);
            $this->connections->event('test_message_failed', $connection, $user, $request, $connection->status, 'Falha no envio da mensagem individual de teste.', $exception->errorCode, $exception->userMessage(), ['request_id' => $requestId]);
            $this->audit->log('whatsapp.test_message_failed', 'Falha no envio da mensagem individual de teste.', $testMessage, null, ['request_id' => $requestId, 'error_code' => $exception->errorCode], $user, $request);

            throw ValidationException::withMessages(['message' => $exception->userMessage()]);
        }

        return $testMessage;
    }

    public function recordDuplicateRequest(string $requestId): void
    {
        if (WhatsAppTestMessage::query()->where('request_id', $requestId)->exists()) {
            throw ValidationException::withMessages(['request_id' => 'Esta solicitacao de envio ja foi registrada.']);
        }
    }

    private function validateContact(Contact $contact): void
    {
        if ($contact->status !== ContactStatus::Active) {
            throw ValidationException::withMessages(['contact_id' => 'Somente contatos ativos podem receber mensagem de teste.']);
        }

        if ($contact->do_not_contact) {
            throw ValidationException::withMessages(['contact_id' => 'Este contato esta marcado como nao contatar.']);
        }

        if (blank($contact->phone_normalized)) {
            throw ValidationException::withMessages(['contact_id' => 'O contato precisa ter telefone valido para receber teste.']);
        }
    }
}
