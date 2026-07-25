<?php

namespace Database\Factories;

use App\Enums\MonitoringHealthStatus;
use App\Models\WorkerHeartbeat;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<WorkerHeartbeat> */
class WorkerHeartbeatFactory extends Factory
{
    protected $model = WorkerHeartbeat::class;

    public function definition(): array
    {
        return [
            'worker_name' => 'worker-'.fake()->unique()->numberBetween(1, 999),
            'queue' => 'whatsapp-messages',
            'hostname' => 'localhost',
            'process_identifier' => (string) fake()->numberBetween(1000, 9999),
            'last_heartbeat_at' => now(),
            'last_job_at' => now(),
            'status' => MonitoringHealthStatus::Healthy,
            'metadata' => [],
        ];
    }
}
