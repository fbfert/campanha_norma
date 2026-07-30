<?php

namespace App\Console\Commands;

use App\Enums\MessageRecipientProcessingStatus;
use App\Models\MessageBatchRecipient;
use App\Services\MessageProcessing\BatchProgressService;
use App\Services\MessageProcessing\MessageProcessingEventService;
use App\Services\SystemSettingService;
use Illuminate\Console\Command;

class RecoverStuckMessagesCommand extends Command
{
    protected $signature = 'messages:recover-stuck';

    protected $description = 'Marca destinatários presos em processamento como resultado incerto.';

    public function handle(SystemSettingService $settings, MessageProcessingEventService $events, BatchProgressService $progress): int
    {
        $minutes = (int) $settings->get('messages.processing_timeout_minutes', 10);
        $count = 0;

        MessageBatchRecipient::query()
            ->where('processing_status', MessageRecipientProcessingStatus::Processing)
            ->where('processing_started_at', '<=', now()->subMinutes($minutes))
            ->each(function (MessageBatchRecipient $recipient) use ($events, $progress, &$count): void {
                $recipient->forceFill([
                    'processing_status' => MessageRecipientProcessingStatus::FailedTemporary,
                    'failed_at' => now(),
                    'error_code' => 'SEND_RESULT_UNKNOWN',
                    'error_message' => 'Resultado incerto após encerramento inesperado do processamento.',
                ])->save();

                $events->record($recipient->batch, 'recipient_failed', 'Resultado incerto identificado em recuperação.', $recipient, errorCode: 'SEND_RESULT_UNKNOWN');
                $progress->sync($recipient->batch);
                $count++;
            });

        $this->info("Destinatários recuperados: {$count}");

        return self::SUCCESS;
    }
}
