<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SchedulerHeartbeat extends Model
{
    use HasFactory;

    protected $fillable = ['hostname', 'last_run_at', 'last_success_at', 'last_error_at', 'error_message'];

    protected function casts(): array
    {
        return [
            'last_run_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }
}
