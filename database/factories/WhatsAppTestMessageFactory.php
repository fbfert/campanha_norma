<?php

namespace Database\Factories;

use App\Enums\WhatsAppTestMessageStatus;
use App\Models\Contact;
use App\Models\User;
use App\Models\WhatsAppTestMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WhatsAppTestMessage> */
class WhatsAppTestMessageFactory extends Factory
{
    protected $model = WhatsAppTestMessage::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'user_id' => User::factory(),
            'request_id' => (string) Str::uuid(),
            'phone_snapshot' => '5549999999999',
            'message' => 'Mensagem individual de teste.',
            'status' => WhatsAppTestMessageStatus::Pending,
        ];
    }
}
