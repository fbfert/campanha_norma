<?php

namespace Database\Factories;

use App\Enums\WhatsAppConnectionStatus;
use App\Models\User;
use App\Models\WhatsAppConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WhatsAppConnection> */
class WhatsAppConnectionFactory extends Factory
{
    protected $model = WhatsAppConnection::class;

    public function definition(): array
    {
        return [
            'provider' => 'web',
            'status' => WhatsAppConnectionStatus::Disconnected,
            'phone_number' => null,
            'display_name' => null,
            'session_identifier' => 'default',
            'metadata' => [],
            'created_by' => User::factory(),
        ];
    }
}
