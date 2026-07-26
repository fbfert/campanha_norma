<?php

namespace App\Services\WhatsApp;

use App\Data\WhatsApp\ConnectionResult;
use App\Data\WhatsApp\ConnectionStatus;
use App\Data\WhatsApp\QrCodeResult;
use App\Data\WhatsApp\SendResult;
use App\Exceptions\WhatsApp\WhatsAppServiceException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WhatsAppServiceClient
{
    public function status(): ConnectionStatus
    {
        return ConnectionStatus::fromArray($this->send('get', '/api/status'));
    }

    public function qrcode(): QrCodeResult
    {
        return QrCodeResult::fromArray($this->send('get', '/api/qrcode'));
    }

    public function connect(): ConnectionResult
    {
        return ConnectionResult::fromArray($this->send('post', '/api/connect'));
    }

    public function reconnect(): ConnectionResult
    {
        return ConnectionResult::fromArray($this->send('post', '/api/reconnect'));
    }

    public function disconnect(): ConnectionResult
    {
        return ConnectionResult::fromArray($this->send('post', '/api/disconnect'));
    }

    public function clearSession(): ConnectionResult
    {
        return ConnectionResult::fromArray($this->send('delete', '/api/session'));
    }

    public function sendTestMessage(string $phone, string $message, string $requestId): SendResult
    {
        return $this->sendMessage($phone, $message, $requestId);
    }

    public function sendMessage(string $phone, string $message, string $requestId): SendResult
    {
        return SendResult::fromArray($this->send('post', '/api/test-message', [
            'request_id' => $requestId,
            'phone' => $phone,
            'message' => $message,
        ]));
    }

    public function listConversations(array $options = []): array
    {
        return $this->send('get', '/api/conversations'.($options === [] ? '' : '?'.http_build_query($options)));
    }

    public function fetchConversationMessages(string $externalChatId, array $options = []): array
    {
        $path = '/api/conversations/'.rawurlencode($externalChatId).'/messages';

        return $this->send('get', $path.($options === [] ? '' : '?'.http_build_query($options)));
    }

    private function request(): PendingRequest
    {
        $token = (string) config('whatsapp.service.token');

        return Http::baseUrl((string) config('whatsapp.service.url'))
            ->withToken($token)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('whatsapp.service.timeout'))
            ->connectTimeout((int) config('whatsapp.service.connect_timeout'));
    }

    private function send(string $method, string $path, array $payload = []): array
    {
        try {
            /** @var Response $response */
            $response = $this->request()->{$method}($path, $payload);
        } catch (ConnectionException $exception) {
            throw new WhatsAppServiceException('SERVICE_UNAVAILABLE', 'O servico de conexao com o WhatsApp esta indisponivel.', 0, [
                'exception' => $exception::class,
            ]);
        }

        return $this->data($response);
    }

    private function data(Response $response): array
    {
        if ($response->failed()) {
            $payload = $response->json();
            $error = is_array($payload) ? ($payload['error'] ?? []) : [];

            throw new WhatsAppServiceException(
                (string) ($error['code'] ?? ($response->status() === 401 ? 'UNAUTHORIZED_SERVICE_REQUEST' : 'SERVICE_UNAVAILABLE')),
                (string) ($error['message'] ?? 'Falha na comunicacao com o servico do WhatsApp.'),
                $response->status(),
                is_array($payload) ? $payload : []
            );
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload['success'] ?? null) !== true || ! array_key_exists('data', $payload)) {
            throw new WhatsAppServiceException('INVALID_RESPONSE', 'Resposta invalida do servico do WhatsApp.');
        }

        return is_array($payload['data']) ? $payload['data'] : [];
    }

    public function newRequestId(): string
    {
        return (string) Str::uuid();
    }
}
