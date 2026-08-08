<?php

namespace App\Contracts;

use App\Data\WhatsApp\ConnectionStatus;
use App\Data\WhatsApp\SendResult;

/**
 * O que todo provedor de WhatsApp precisa saber fazer.
 *
 * Só o essencial: dizer se está de pé e mandar mensagem. Parear sessão e ler
 * histórico ficaram em contratos próprios — `PairsBySession` e
 * `ReadsConversationHistory` — porque não existem na API oficial da Meta, e
 * exigi-los de todo provedor obrigaria o próximo a fingir que os tem.
 */
interface WhatsAppProvider
{
    public function getStatus(): ConnectionStatus;

    public function sendTestMessage(string $phone, string $message, string $requestId): SendResult;

    public function sendMessage(string $phone, string $message, string $requestId): SendResult;
}
