<?php

namespace App\Jobs;

use App\Models\MessageBatchRecipient;
use App\Services\MessageProcessing\RecipientProcessingService;
use App\Services\Monitoring\HeartbeatService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendMessageRecipientJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $recipientId, public readonly int $processingVersion)
    {
        $this->onQueue('whatsapp-messages');
    }

    public function handle(RecipientProcessingService $service, HeartbeatService $heartbeat): void
    {
        $heartbeat->worker('whatsapp-messages', self::class);
        $recipient = MessageBatchRecipient::query()->find($this->recipientId);

        if ($recipient) {
            $service->process($recipient, $this->processingVersion);
        }
    }
}
