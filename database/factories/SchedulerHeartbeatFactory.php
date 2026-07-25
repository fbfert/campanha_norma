<?php

namespace Database\Factories;

use App\Models\SchedulerHeartbeat;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SchedulerHeartbeat> */
class SchedulerHeartbeatFactory extends Factory
{
    protected $model = SchedulerHeartbeat::class;

    public function definition(): array
    {
        return [
            'hostname' => 'scheduler-'.fake()->unique()->numberBetween(1, 999),
            'last_run_at' => now(),
            'last_success_at' => now(),
        ];
    }
}
