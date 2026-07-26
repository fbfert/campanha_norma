<?php

namespace App\Services\WhatsApp;

use App\Contracts\WhatsAppProvider;
use App\Data\WhatsApp\ConnectionResult;
use App\Data\WhatsApp\ConnectionStatus;
use App\Data\WhatsApp\QrCodeResult;
use App\Data\WhatsApp\SendResult;

class WhatsAppWebProvider implements WhatsAppProvider
{
    public function __construct(private readonly WhatsAppServiceClient $client) {}

    public function getStatus(): ConnectionStatus
    {
        return $this->client->status();
    }

    public function requestQrCode(): QrCodeResult
    {
        return $this->client->qrcode();
    }

    public function connect(): ConnectionResult
    {
        return $this->client->connect();
    }

    public function reconnect(): ConnectionResult
    {
        return $this->client->reconnect();
    }

    public function disconnect(): ConnectionResult
    {
        return $this->client->disconnect();
    }

    public function clearSession(): ConnectionResult
    {
        return $this->client->clearSession();
    }

    public function sendTestMessage(string $phone, string $message, string $requestId): SendResult
    {
        return $this->client->sendTestMessage($phone, $message, $requestId);
    }

    public function sendMessage(string $phone, string $message, string $requestId): SendResult
    {
        return $this->client->sendMessage($phone, $message, $requestId);
    }

    public function listConversations(array $options = []): array
    {
        return $this->client->listConversations($options);
    }

    public function fetchConversationMessages(string $externalChatId, array $options = []): array
    {
        return $this->client->fetchConversationMessages($externalChatId, $options);
    }
}
