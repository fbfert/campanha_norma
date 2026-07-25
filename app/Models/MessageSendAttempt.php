<?php

namespace App\Models;

use App\Enums\MessageSendAttemptStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageSendAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_batch_recipient_id',
        'attempt_number',
        'request_id',
        'status',
        'provider',
        'started_at',
        'finished_at',
        'external_message_id',
        'error_code',
        'error_message',
        'response_metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => MessageSendAttemptStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'response_metadata' => 'array',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(MessageBatchRecipient::class, 'message_batch_recipient_id');
    }
}
