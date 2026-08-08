<?php

namespace App\Contracts;

use App\Data\WhatsApp\ConnectionResult;
use App\Data\WhatsApp\QrCodeResult;

/**
 * Provedor que se conecta parenado uma sessão, como o WhatsApp Web.
 *
 * QR Code, reconectar e limpar sessão só existem nesse modelo. A API oficial da
 * Meta autentica por credencial permanente: não há o que parear, nem o que
 * limpar, e exigir esses métodos de todo provedor obrigaria o próximo a fingir
 * que os tem.
 */
interface PairsBySession
{
    public function requestQrCode(): QrCodeResult;

    public function connect(): ConnectionResult;

    public function reconnect(): ConnectionResult;

    public function disconnect(): ConnectionResult;

    public function clearSession(): ConnectionResult;
}
