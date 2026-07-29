<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConversationFlowQuestion extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'conversation_flow_id',
        'internal_title',
        'text',
        'category',
        'weight',
        'display_order',
        'is_active',
        'version',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'display_order' => 'integer',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(ConversationFlow::class, 'conversation_flow_id');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(ConversationFlowQuestionUsage::class, 'conversation_flow_question_id');
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
