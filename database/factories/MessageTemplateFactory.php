<?php

namespace Database\Factories;

use App\Enums\MessageTemplateStatus;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MessageTemplate> */
class MessageTemplateFactory extends Factory
{
    protected $model = MessageTemplate::class;

    public function definition(): array
    {
        $name = 'Modelo '.fake()->unique()->word();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => 'Modelo de teste.',
            'body' => 'Oi {primeiro_nome}, como esta {cidade}?',
            'status' => MessageTemplateStatus::Active,
            'version' => 1,
            'created_by' => User::factory(),
        ];
    }
}
