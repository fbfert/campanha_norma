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

    /**
     * Baixa a midia de uma mensagem, em base64.
     *
     * O serviço não guarda arquivo: busca sob demanda, entrega e esquece. Quem
     * chama transcreve e descarta.
     */
    public function fetchMessageMedia(string $externalChatId, string $externalMessageId, array $options = []): array
    {
        $path = '/api/conversations/'.rawurlencode($externalChatId).'/messages/'.rawurlencode($externalMessageId).'/media';

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
            $request = $this->request();

            if ($payload !== []) {
                // json_encode()/Guzzle's default JSON encoding escapes unicode as \uXXXX,
                // which inflates emoji-heavy bodies ~3x over the wire and can trip the
                // whatsapp-service request size limit. Encoding it ourselves keeps the
                // raw UTF-8 (utf8mb4) bytes instead.
                $request = $request->withBody(
                    json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'application/json'
                );
            }

            /** @var Response $response */
            $response = $request->{$method}($path);
        } catch (ConnectionException $exception) {
            throw new WhatsAppServiceException('SERVICE_UNAVAILABLE', 'O serviço de conexão com o WhatsApp esta indisponível.', 0, [
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
                (string) ($error['message'] ?? 'Falha na comunicação com o serviço do WhatsApp.'),
                $response->status(),
                is_array($payload) ? $payload : []
            );
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload['success'] ?? null) !== true || ! array_key_exists('data', $payload)) {
            throw new WhatsAppServiceException('INVALID_RESPONSE', 'Resposta invalida do serviço do WhatsApp.');
        }

        return is_array($payload['data']) ? $payload['data'] : [];
    }

    public function newRequestId(): string
    {
        return (string) Str::uuid();
    }
}
