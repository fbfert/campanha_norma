<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageProcessingEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_batch_id',
        'message_batch_recipient_id',
        'user_id',
        'event_type',
        'status',
        'description',
        'error_code',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MessageBatch::class, 'message_batch_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(MessageBatchRecipient::class, 'message_batch_recipient_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
