<?php

namespace App\Data\WhatsApp;

use App\Enums\WhatsAppConnectionStatus;
use Carbon\CarbonImmutable;

readonly class ConnectionStatus
{
    public function __construct(
        public WhatsAppConnectionStatus $status,
        public ?string $phoneNumber = null,
        public ?string $displayName = null,
        public ?CarbonImmutable $connectedAt = null,
        public ?CarbonImmutable $lastActivityAt = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public bool $browserReady = false,
        public bool $sessionAvailable = false,
        public array $metadata = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            status: WhatsAppConnectionStatus::tryFrom((string) ($data['status'] ?? 'service_error')) ?? WhatsAppConnectionStatus::ServiceError,
            phoneNumber: $data['phone_number'] ?? null,
            displayName: $data['display_name'] ?? null,
            connectedAt: self::date($data['connected_at'] ?? null),
            lastActivityAt: self::date($data['last_activity_at'] ?? null),
            errorCode: $data['error_code'] ?? null,
            errorMessage: $data['error_message'] ?? null,
            browserReady: (bool) ($data['browser_ready'] ?? false),
            sessionAvailable: (bool) ($data['session_available'] ?? false),
            metadata: collect($data)->except(['status', 'phone_number', 'display_name', 'connected_at', 'last_activity_at', 'error_code', 'error_message'])->all(),
        );
    }

    /**
     * Converte para o fuso local antes de entregar ao resto do sistema.
     *
     * O serviço Node manda o instante em UTC, com `Z` no fim. `parse` respeita
     * esse fuso e devolve um Carbon em UTC; o Eloquent grava a hora **no fuso
     * que o objeto carrega**, e a leitura de volta interpreta a coluna como
     * hora local. Sem esta conversão, uma conexão das 12:21 fica gravada como
     * 15:21 e a tela mostra um horário três horas no futuro.
     *
     * É o mesmo defeito que já corrigimos nos horários de mensagem, e a correção
     * fica aqui, na fronteira, para valer também para quem consumir estes
     * campos depois.
     */
    private static function date(?string $value): ?CarbonImmutable
    {
        return $value
            ? CarbonImmutable::parse($value)->setTimezone(config('app.timezone'))
            : null;
    }
}
