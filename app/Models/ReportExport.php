<?php

namespace App\Models;

use App\Enums\ReportExportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'report_type',
        // Etapa 9E: escopo, finalidade e sal do pseudonimo.
        'scope',
        'purpose',
        'anonymized',
        'pseudonym_salt',
        'format',
        'status',
        'filters',
        'columns',
        'file_path',
        'file_size',
        'total_rows',
        'started_at',
        'finished_at',
        'expires_at',
        'error_code',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReportExportStatus::class,
            'filters' => 'array',
            'columns' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
