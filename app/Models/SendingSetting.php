<?php

namespace App\Models;

use App\Enums\RetryBackoffType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SendingSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'max_per_minute',
        'max_per_hour',
        'max_per_day',
        'minimum_interval_seconds',
        'start_time',
        'end_time',
        'allowed_weekdays',
        'timezone',
        'max_attempts',
        'retry_interval_minutes',
        'retry_backoff_type',
        'pause_when_disconnected',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'allowed_weekdays' => 'array',
            'retry_backoff_type' => RetryBackoffType::class,
            'pause_when_disconnected' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
