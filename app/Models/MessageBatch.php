<?php

namespace App\Models;

use App\Enums\MessageBatchSelectionType;
use App\Enums\MessageBatchStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MessageBatch extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'description',
        'message_template_id',
        'message_template_version',
        'message_body_snapshot',
        'placeholders_snapshot',
        'selection_type',
        'selection_filters',
        'selection_total',
        'eligible_total',
        'ineligible_total',
        'status',
        'random_seed',
        'created_by',
        'updated_by',
        'prepared_at',
        'queued_at',
        'processing_started_at',
        'pause_requested_at',
        'paused_at',
        'resume_requested_at',
        'stop_requested_at',
        'stopped_at',
        'completed_at',
        'failed_at',
        'last_dispatch_at',
        'next_dispatch_at',
        'total_pending',
        'total_queued',
        'total_processing',
        'total_sent',
        'total_failed',
        'total_cancelled',
        'total_retrying',
        'processing_version',
        'last_error_code',
        'last_error_message',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'placeholders_snapshot' => 'array',
            'selection_filters' => 'array',
            'selection_type' => MessageBatchSelectionType::class,
            'status' => MessageBatchStatus::class,
            'prepared_at' => 'datetime',
            'queued_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'pause_requested_at' => 'datetime',
            'paused_at' => 'datetime',
            'resume_requested_at' => 'datetime',
            'stop_requested_at' => 'datetime',
            'stopped_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
            'last_dispatch_at' => 'datetime',
            'next_dispatch_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(MessageBatchRecipient::class)->orderByRaw('random_position is null, random_position asc');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MessageBatchEvent::class)->latest('created_at');
    }

    public function processingEvents(): HasMany
    {
        return $this->hasMany(MessageProcessingEvent::class)->latest('created_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
