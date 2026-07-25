<?php

namespace Database\Factories;

use App\Enums\MessageBatchSelectionType;
use App\Enums\MessageBatchStatus;
use App\Models\MessageBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MessageBatch> */
class MessageBatchFactory extends Factory
{
    protected $model = MessageBatch::class;

    public function definition(): array
    {
        return [
            'name' => 'Lote '.fake()->unique()->word(),
            'is_campaign' => false,
            'message_body_snapshot' => 'Oi {primeiro_nome}.',
            'campaign_templates_snapshot' => null,
            'placeholders_snapshot' => ['primeiro_nome'],
            'selection_type' => MessageBatchSelectionType::Manual,
            'selection_filters' => [],
            'status' => MessageBatchStatus::Draft,
            'random_seed' => bin2hex(random_bytes(4)),
            'created_by' => User::factory(),
        ];
    }
}
