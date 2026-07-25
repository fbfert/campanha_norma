<?php

namespace Database\Factories;

use App\Models\MessageTemplate;
use App\Models\MessageTemplateVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MessageTemplateVersion> */
class MessageTemplateVersionFactory extends Factory
{
    protected $model = MessageTemplateVersion::class;

    public function definition(): array
    {
        return [
            'message_template_id' => MessageTemplate::factory(),
            'version' => 1,
            'name' => 'Modelo de teste',
            'body' => 'Oi {primeiro_nome}.',
            'placeholders' => ['primeiro_nome'],
            'created_by' => User::factory(),
        ];
    }
}
