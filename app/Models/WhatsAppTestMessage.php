<?php

namespace App\Models;

use App\Enums\WhatsAppTestMessageStatus;
use App\Support\MantemChaveDeLixeira;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WhatsAppTestMessage extends Model
{
    use HasFactory, MantemChaveDeLixeira, SoftDeletes;

    protected $table = 'whatsapp_test_messages';

    protected $fillable = [
        'contact_id',
        'user_id',
        'request_id',
        'phone_snapshot',
        'message',
        'status',
        'external_message_id',
        'sent_at',
        'failed_at',
        'error_code',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => WhatsAppTestMessageStatus::class,
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
