<?php

namespace App\Models;

use App\Enums\WhatsAppConnectionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppConnectionEvent extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'whatsapp_connection_events';

    protected $fillable = [
        'whatsapp_connection_id',
        'user_id',
        'event_type',
        'status',
        'description',
        'error_code',
        'error_message',
        'metadata',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'status' => WhatsAppConnectionStatus::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConnection::class, 'whatsapp_connection_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
