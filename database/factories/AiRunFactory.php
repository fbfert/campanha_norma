<?php

namespace Database\Factories;

use App\Enums\AiRunPurpose;
use App\Models\AiRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiRun> */
class AiRunFactory extends Factory
{
    protected $model = AiRun::class;

    public function definition(): array
    {
        return [
            'purpose' => AiRunPurpose::ExtractInsight,
            'provider' => 'openai',
            'model' => 'gpt-4.1-mini',
            'prompt_version' => 'v1',
            'schema_version' => 1,
            'status' => 'succeeded',
            // Identifica o pedido para a idempotência do cliente; único por run.
            'request_hash' => fake()->unique()->sha256(),
            'prompt_tokens' => 1000,
            'completion_tokens' => 200,
            'total_tokens' => 1200,
            'latency_ms' => 800,
            'attempt' => 1,
            'started_at' => now(),
            'completed_at' => now(),
        ];
    }

    public function failed(): self
    {
        return $this->state(fn (): array => [
            'status' => 'invalid_output',
            'error_code' => 'INVALID_RESPONSE',
            'completion_tokens' => 0,
        ]);
    }
}
