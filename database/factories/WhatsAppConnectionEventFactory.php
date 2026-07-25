<?php

namespace Database\Factories;

use App\Enums\WhatsAppConnectionStatus;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppConnectionEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WhatsAppConnectionEvent> */
class WhatsAppConnectionEventFactory extends Factory
{
    protected $model = WhatsAppConnectionEvent::class;

    public function definition(): array
    {
        return [
            'whatsapp_connection_id' => WhatsAppConnection::factory(),
            'event_type' => 'service_started',
            'status' => WhatsAppConnectionStatus::Disconnected,
            'description' => 'Evento tecnico de teste.',
            'metadata' => [],
        ];
    }
}
