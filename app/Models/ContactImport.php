<?php

namespace App\Models;

use App\Enums\ContactImportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'original_filename', 'stored_filename', 'status', 'total_rows', 'valid_rows',
        'invalid_rows', 'created_rows', 'updated_rows', 'ignored_rows', 'duplicate_rows',
        'error_rows', 'options', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContactImportStatus::class,
            'options' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ContactImportRow::class);
    }
}
