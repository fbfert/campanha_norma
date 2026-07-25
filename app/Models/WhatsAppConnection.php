<?php

namespace App\Models;

use App\Enums\WhatsAppConnectionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppConnection extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_connections';

    protected $fillable = [
        'provider',
        'status',
        'phone_number',
        'display_name',
        'session_identifier',
        'connected_at',
        'disconnected_at',
        'last_activity_at',
        'last_status_check_at',
        'last_qr_generated_at',
        'last_error_code',
        'last_error_message',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => WhatsAppConnectionStatus::class,
            'connected_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'last_status_check_at' => 'datetime',
            'last_qr_generated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(WhatsAppConnectionEvent::class, 'whatsapp_connection_id')->latest('created_at');
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
