<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyMessageMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'date',
        'provider',
        'total_prepared',
        'total_processed',
        'total_sent',
        'total_failed',
        'total_cancelled',
        'total_skipped',
        'total_attempts',
        'total_waiting_minutes',
    ];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
