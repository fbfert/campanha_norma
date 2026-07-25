<?php

namespace App\Services\WhatsApp;

use App\Data\WhatsApp\ConnectionResult;
use App\Data\WhatsApp\ConnectionStatus;
use App\Data\WhatsApp\QrCodeResult;
use App\Enums\WhatsAppConnectionStatus;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppConnectionEvent;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class WhatsAppConnectionService
{
    public function __construct(
        private readonly WhatsAppProviderManager $manager,
        private readonly AuditLogger $audit,
    ) {}

    public function connection(?User $user = null): WhatsAppConnection
    {
        return WhatsAppConnection::query()->firstOrCreate(
            ['provider' => 'web'],
            [
                'status' => WhatsAppConnectionStatus::NotInitialized,
                'created_by' => $user?->id,
                'updated_by' => $user?->id,
            ]
        );
    }

    public function refreshStatus(?User $user = null): ConnectionStatus
    {
        $status = $this->manager->provider()->getStatus();
        $this->syncStatus($status, $user);

        return $status;
    }

    public function connect(User $user, Request $request): ConnectionResult
    {
        $result = $this->manager->provider()->connect();
        $this->syncResult($result, 'connect_requested', 'Inicializacao da conexao WhatsApp solicitada.', $user, $request);
        $this->audit->log('whatsapp.connect_requested', 'Inicializacao da conexao WhatsApp solicitada.', $this->connection($user), null, ['status' => $result->status->value], $user, $request);

        return $result;
    }

    public function reconnect(User $user, Request $request): ConnectionResult
    {
        $result = $this->manager->provider()->reconnect();
        $this->syncResult($result, 'reconnect_requested', 'Reconexao WhatsApp solicitada.', $user, $request);
        $this->audit->log('whatsapp.reconnect_requested', 'Reconexao WhatsApp solicitada.', $this->connection($user), null, ['status' => $result->status->value], $user, $request);

        return $result;
    }

    public function disconnect(User $user, Request $request): ConnectionResult
    {
        $result = $this->manager->provider()->disconnect();
        $this->syncResult($result, 'disconnect_requested', 'Desconexao WhatsApp solicitada.', $user, $request);
        $this->audit->log('whatsapp.disconnect_requested', 'Desconexao WhatsApp solicitada.', $this->connection($user), null, ['status' => $result->status->value], $user, $request);

        return $result;
    }

    public function clearSession(User $user, Request $request): ConnectionResult
    {
        $result = $this->manager->provider()->clearSession();
        $this->syncResult($result, 'session_clear_requested', 'Exclusao da sessao WhatsApp solicitada.', $user, $request);
        $this->audit->log('whatsapp.session_clear_requested', 'Exclusao da sessao WhatsApp solicitada.', $this->connection($user), null, ['status' => $result->status->value], $user, $request);

        return $result;
    }

    public function requestQr(User $user, Request $request): QrCodeResult
    {
        $result = $this->manager->provider()->requestQrCode();
        $connection = $this->connection($user);
        $connection->forceFill([
            'status' => $result->status,
            'last_qr_generated_at' => $result->generatedAt,
            'last_error_code' => $result->errorCode,
            'last_error_message' => $result->errorMessage,
            'updated_by' => $user->id,
        ])->save();

        $this->event('qr_requested', $connection, $user, $request, $result->status, 'QR Code solicitado.', $result->errorCode, $result->errorMessage);
        $this->audit->log('whatsapp.qr_requested', 'QR Code WhatsApp solicitado.', $connection, null, ['status' => $result->status->value], $user, $request);

        return $result;
    }

    public function syncStatus(ConnectionStatus $status, ?User $user = null): WhatsAppConnection
    {
        $connection = $this->connection($user);
        $connection->forceFill([
            'status' => $status->status,
            'phone_number' => $status->phoneNumber,
            'display_name' => $status->displayName,
            'connected_at' => $status->connectedAt,
            'last_activity_at' => $status->lastActivityAt,
            'last_status_check_at' => now(),
            'last_error_code' => $status->errorCode,
            'last_error_message' => $status->errorMessage,
            'metadata' => $status->metadata,
            'updated_by' => $user?->id,
        ])->save();

        return $connection;
    }

    private function syncResult(ConnectionResult $result, string $event, string $description, User $user, Request $request): WhatsAppConnection
    {
        $connection = $this->connection($user);
        $connection->forceFill([
            'status' => $result->status,
            'last_status_check_at' => now(),
            'last_error_code' => $result->errorCode,
            'last_error_message' => $result->errorMessage,
            'updated_by' => $user->id,
        ])->save();

        $this->event($event, $connection, $user, $request, $result->status, $description, $result->errorCode, $result->errorMessage);

        return $connection;
    }

    public function event(string $event, WhatsAppConnection $connection, ?User $user, ?Request $request, ?WhatsAppConnectionStatus $status, ?string $description = null, ?string $errorCode = null, ?string $errorMessage = null, ?array $metadata = null): WhatsAppConnectionEvent
    {
        return WhatsAppConnectionEvent::create([
            'whatsapp_connection_id' => $connection->id,
            'user_id' => $user?->id,
            'event_type' => $event,
            'status' => $status,
            'description' => $description,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'metadata' => $metadata,
            'ip_address' => $request?->ip(),
        ]);
    }
}
