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
            /*
             | Não conseguir falar com o serviço e ele demorar demais para
             | responder são coisas diferentes, e o Guzzle entrega as duas como
             | a mesma exceção. Chamar tudo de "indisponível" manda quem lê
             | conferir se o serviço está de pé — e, quando ele está de pé e só
             | travado, essa é a única pista que a tela dá, apontando para o
             | lugar errado.
             |
             | Aconteceu: seis sincronizações seguidas falharam dizendo
             | "indisponível" enquanto o serviço respondia normalmente. O que
             | estava travado era a página do navegador, morta depois de o
             | Chromium ser derrubado por falta de memória.
             */
            $tempoEsgotado = str_contains(mb_strtolower($exception->getMessage()), 'timed out')
                || str_contains(mb_strtolower($exception->getMessage()), 'timeout');

            throw new WhatsAppServiceException(
                $tempoEsgotado ? 'SERVICE_TIMEOUT' : 'SERVICE_UNAVAILABLE',
                $tempoEsgotado
                    ? 'O serviço do WhatsApp não respondeu a tempo. Ele pode estar de pé e travado: confira a conexão e, se necessário, reinicie o serviço.'
                    : 'O serviço de conexão com o WhatsApp está indisponível.',
                0,
                ['exception' => $exception::class, 'timeout_seconds' => (int) config('whatsapp.service.timeout')],
            );
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
