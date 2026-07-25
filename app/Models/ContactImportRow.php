<?php

namespace App\Models;

use App\Enums\ContactImportRowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactImportRow extends Model
{
    protected $fillable = ['contact_import_id', 'row_number', 'raw_data', 'normalized_data', 'status', 'contact_id', 'error_messages'];

    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
            'normalized_data' => 'array',
            'status' => ContactImportRowStatus::class,
            'error_messages' => 'array',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(ContactImport::class, 'contact_import_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
