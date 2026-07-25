<?php

namespace App\Models;

use App\Enums\ConsentStatus;
use App\Enums\ContactSource;
use App\Enums\ContactStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'first_name', 'phone', 'phone_normalized', 'email', 'city', 'state', 'country',
        'notes', 'status', 'source', 'consent_status', 'consent_source', 'consent_text',
        'consent_at', 'do_not_contact', 'do_not_contact_at', 'do_not_contact_reason',
        'last_contacted_at', 'has_replied', 'first_replied_at', 'last_replied_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ContactStatus::class,
            'source' => ContactSource::class,
            'consent_status' => ConsentStatus::class,
            'consent_at' => 'date',
            'do_not_contact' => 'boolean',
            'do_not_contact_at' => 'datetime',
            'last_contacted_at' => 'datetime',
            'has_replied' => 'boolean',
            'first_replied_at' => 'datetime',
            'last_replied_at' => 'datetime',
        ];
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withPivot('created_by');
    }

    public function history(): HasMany
    {
        return $this->hasMany(ContactHistory::class)->latest('created_at');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
