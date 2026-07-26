<?php

namespace App\Models;

use App\Enums\ConversationSyncStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationSyncRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'status',
        'requested_by',
        'started_at',
        'finished_at',
        'last_heartbeat_at',
        'chats_found',
        'chats_processed',
        'chats_failed',
        'messages_found',
        'messages_imported',
        'messages_skipped',
        'messages_failed',
        'error_code',
        'error_message',
        'options',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConversationSyncStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'options' => 'array',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
