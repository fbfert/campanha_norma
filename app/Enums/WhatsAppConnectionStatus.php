<?php

namespace App\Enums;

enum WhatsAppConnectionStatus: string
{
    case NotInitialized = 'not_initialized';
    case Starting = 'starting';
    case GeneratingQr = 'generating_qr';
    case WaitingForQrScan = 'waiting_for_qr_scan';
    case Authenticating = 'authenticating';
    case Connected = 'connected';
    case Reconnecting = 'reconnecting';
    case Disconnecting = 'disconnecting';
    case Disconnected = 'disconnected';
    case SessionExpired = 'session_expired';
    case AuthenticationFailed = 'authentication_failed';
    case BrowserError = 'browser_error';
    case ServiceError = 'service_error';

    public function label(): string
    {
        return match ($this) {
            self::NotInitialized => 'Não inicializado',
            self::Starting => 'Inicializando',
            self::GeneratingQr => 'Gerando QR Code',
            self::WaitingForQrScan => 'Aguardando leitura do QR Code',
            self::Authenticating => 'Autenticando',
            self::Connected => 'Conectado',
            self::Reconnecting => 'Reconectando',
            self::Disconnecting => 'Desconectando',
            self::Disconnected => 'Desconectado',
            self::SessionExpired => 'Sessão expirada',
            self::AuthenticationFailed => 'Falha de autenticação',
            self::BrowserError => 'Erro no navegador',
            self::ServiceError => 'Erro no serviço',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Connected => '#176b4d',
            self::WaitingForQrScan, self::GeneratingQr, self::Authenticating => '#b7791f',
            self::Starting, self::Reconnecting => '#1f6f8b',
            self::Disconnected, self::NotInitialized, self::Disconnecting => '#5b6776',
            self::SessionExpired, self::AuthenticationFailed, self::BrowserError, self::ServiceError => '#b42318',
        };
    }
}
