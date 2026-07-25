<?php

namespace Database\Factories;

use App\Enums\MessageBatchRecipientEligibility;
use App\Enums\MessageRecipientProcessingStatus;
use App\Models\Contact;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MessageBatchRecipient> */
class MessageBatchRecipientFactory extends Factory
{
    protected $model = MessageBatchRecipient::class;

    public function definition(): array
    {
        return [
            'message_batch_id' => MessageBatch::factory(),
            'contact_id' => Contact::factory(),
            'message_template_id' => null,
            'message_template_version' => null,
            'message_template_name_snapshot' => null,
            'random_position' => 1,
            'eligibility_status' => MessageBatchRecipientEligibility::Eligible,
            'processing_status' => MessageRecipientProcessingStatus::Eligible,
            'attempts' => 0,
            'max_attempts' => 3,
            'contact_name_snapshot' => 'Contato Teste',
            'contact_first_name_snapshot' => 'Contato',
            'contact_phone_snapshot' => '(49) 99999-9999',
            'contact_city_snapshot' => 'Lages',
            'contact_state_snapshot' => 'SC',
            'contact_country_snapshot' => 'BR',
            'rendered_message' => 'Oi Contato.',
            'render_errors' => [],
        ];
    }
}
