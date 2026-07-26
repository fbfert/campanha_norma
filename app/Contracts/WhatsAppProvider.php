<?php

namespace App\Contracts;

use App\Data\WhatsApp\ConnectionResult;
use App\Data\WhatsApp\ConnectionStatus;
use App\Data\WhatsApp\QrCodeResult;
use App\Data\WhatsApp\SendResult;

interface WhatsAppProvider
{
    public function getStatus(): ConnectionStatus;

    public function requestQrCode(): QrCodeResult;

    public function connect(): ConnectionResult;

    public function reconnect(): ConnectionResult;

    public function disconnect(): ConnectionResult;

    public function clearSession(): ConnectionResult;

    public function sendTestMessage(string $phone, string $message, string $requestId): SendResult;

    public function sendMessage(string $phone, string $message, string $requestId): SendResult;

    public function listConversations(array $options = []): array;

    public function fetchConversationMessages(string $externalChatId, array $options = []): array;
}
