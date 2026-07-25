<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationAssignment extends Model
{
    use HasFactory;

    protected $fillable = ['conversation_id', 'assigned_user_id', 'assigned_by', 'assigned_at', 'unassigned_at', 'unassigned_by', 'reason'];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'unassigned_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
