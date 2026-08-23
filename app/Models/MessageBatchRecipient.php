<?php

namespace App\Models;

use App\Enums\ContactStatus;
use App\Enums\MessageBatchRecipientEligibility;
use App\Enums\MessageRecipientProcessingStatus;
use App\Support\MantemChaveDeLixeira;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MessageBatchRecipient extends Model
{
    use HasFactory, MantemChaveDeLixeira, SoftDeletes;

    protected $fillable = [
        'message_batch_id',
        'contact_id',
        'message_template_id',
        'message_template_version',
        'message_template_name_snapshot',
        'random_position',
        'eligibility_status',
        'processing_status',
        'attempts',
        'max_attempts',
        'request_id',
        'processing_version',
        'queued_at',
        'processing_started_at',
        'sent_at',
        'failed_at',
        'cancelled_at',
        'retry_at',
        'last_attempt_at',
        'external_message_id',
        'error_code',
        'error_message',
        'technical_payload',
        'ineligibility_reason',
        'contact_name_snapshot',
        'contact_first_name_snapshot',
        'contact_phone_snapshot',
        'contact_email_snapshot',
        'contact_city_snapshot',
        'contact_state_snapshot',
        'contact_country_snapshot',
        'rendered_message',
        'render_errors',
    ];

    protected function casts(): array
    {
        return [
            'eligibility_status' => MessageBatchRecipientEligibility::class,
            'processing_status' => MessageRecipientProcessingStatus::class,
            'render_errors' => 'array',
            'technical_payload' => 'array',
            'queued_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'retry_at' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MessageBatch::class, 'message_batch_id');
    }

    /**
     * O contato ainda pode receber mensagem.
     *
     * Esta regra vive aqui, e não dentro do serviço de envio, porque três
     * caminhos precisam dela: o envio, ao conferir na última hora; o
     * descancelamento, para não rearmar quem pediu para sair; e o
     * reprocessamento. Três cópias divergiriam, e a que divergisse seria
     * justamente a que deixa passar.
     */
    public function contactStillEligible(): bool
    {
        $contact = $this->contact;

        return $contact
            && $contact->status === ContactStatus::Active
            && ! $contact->do_not_contact
            && filled($contact->phone_normalized);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(MessageSendAttempt::class);
    }

    public function processingEvents(): HasMany
    {
        return $this->hasMany(MessageProcessingEvent::class);
    }
}
