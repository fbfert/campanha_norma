<?php

namespace App\Models;

use App\Enums\MessageBatchRecipientEligibility;
use App\Enums\MessageRecipientProcessingStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MessageBatchRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_batch_id',
        'contact_id',
        'random_position',
        'eligibility_status',
        'processing_status',
        'attempts',
        'max_attempts',
        'request_id',
        'processing_version',
        'queued_at',
        'processing_started_at',
        'sent_at',
        'failed_at',
        'cancelled_at',
        'retry_at',
        'last_attempt_at',
        'external_message_id',
        'error_code',
        'error_message',
        'technical_payload',
        'ineligibility_reason',
        'contact_name_snapshot',
        'contact_first_name_snapshot',
        'contact_phone_snapshot',
        'contact_email_snapshot',
        'contact_city_snapshot',
        'contact_state_snapshot',
        'contact_country_snapshot',
        'rendered_message',
        'render_errors',
    ];

    protected function casts(): array
    {
        return [
            'eligibility_status' => MessageBatchRecipientEligibility::class,
            'processing_status' => MessageRecipientProcessingStatus::class,
            'render_errors' => 'array',
            'technical_payload' => 'array',
            'queued_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'retry_at' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MessageBatch::class, 'message_batch_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(MessageSendAttempt::class);
    }

    public function processingEvents(): HasMany
    {
        return $this->hasMany(MessageProcessingEvent::class);
    }
}
