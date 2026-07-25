<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageTemplateVersion extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = ['message_template_id', 'version', 'name', 'description', 'body', 'placeholders', 'created_by'];

    protected function casts(): array
    {
        return [
            'placeholders' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
