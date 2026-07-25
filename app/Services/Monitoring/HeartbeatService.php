<?php

namespace App\Services\Monitoring;

use App\Enums\MonitoringHealthStatus;
use App\Models\SchedulerHeartbeat;
use App\Models\WorkerHeartbeat;

class HeartbeatService
{
    public function worker(string $queue, ?string $job = null): WorkerHeartbeat
    {
        return WorkerHeartbeat::query()->updateOrCreate(
            ['worker_name' => gethostname() ?: 'local', 'queue' => $queue],
            [
                'hostname' => gethostname() ?: null,
                'process_identifier' => (string) getmypid(),
                'last_heartbeat_at' => now(),
                'last_job_at' => $job ? now() : null,
                'status' => MonitoringHealthStatus::Healthy,
                'metadata' => ['job' => $job],
            ]
        );
    }

    public function scheduler(?string $error = null): SchedulerHeartbeat
    {
        return SchedulerHeartbeat::query()->updateOrCreate(
            ['hostname' => gethostname() ?: 'local'],
            [
                'last_run_at' => now(),
                'last_success_at' => $error ? null : now(),
                'last_error_at' => $error ? now() : null,
                'error_message' => $error,
            ]
        );
    }
}
