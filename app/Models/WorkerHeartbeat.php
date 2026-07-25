<?php

namespace App\Models;

use App\Enums\MonitoringHealthStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkerHeartbeat extends Model
{
    use HasFactory;

    protected $fillable = ['worker_name', 'queue', 'hostname', 'process_identifier', 'last_heartbeat_at', 'last_job_at', 'status', 'metadata'];

    protected function casts(): array
    {
        return [
            'last_heartbeat_at' => 'datetime',
            'last_job_at' => 'datetime',
            'status' => MonitoringHealthStatus::class,
            'metadata' => 'array',
        ];
    }
}
