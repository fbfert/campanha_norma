<?php

namespace App\Models;

use App\Enums\MessageTemplateStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MessageTemplate extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'slug', 'description', 'body', 'status', 'version', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return [
            'status' => MessageTemplateStatus::class,
            'version' => 'integer',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(MessageTemplateVersion::class)->latest('version');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(MessageBatch::class);
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
