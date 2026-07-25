<?php

namespace App\Services\MessageProcessing;

use App\Enums\ContactStatus;
use App\Enums\MessageBatchStatus;
use App\Enums\MessageRecipientProcessingStatus;
use App\Enums\MessageSendAttemptStatus;
use App\Enums\WhatsAppConnectionStatus;
use App\Exceptions\WhatsApp\WhatsAppServiceException;
use App\Jobs\DispatchMessageBatchJob;
use App\Models\MessageBatchRecipient;
use App\Models\MessageSendAttempt;
use App\Services\WhatsApp\WhatsAppProviderManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecipientProcessingService
{
    public function __construct(
        private readonly SendingSettingsService $settings,
        private readonly SendingWindowService $window,
        private readonly SendingRateLimiterService $rateLimiter,
        private readonly RetryPolicyService $retryPolicy,
        private readonly BatchProgressService $progress,
        private readonly MessageProcessingEventService $events,
        private readonly WhatsAppProviderManager $providers,
    ) {}

    public function process(MessageBatchRecipient $recipient, int $processingVersion): void
    {
        Cache::lock("message-recipient:{$recipient->id}", 120)->block(1, function () use ($recipient, $processingVersion): void {
            $recipient->refresh()->load(['batch', 'contact']);
            $batch = $recipient->batch;

            if ($recipient->processing_version !== $processingVersion || $batch->processing_version !== $processingVersion) {
                return;
            }

            if ($recipient->processing_status === MessageRecipientProcessingStatus::Sent || $recipient->external_message_id) {
                return;
            }

            if (! in_array($batch->status, [MessageBatchStatus::Queued, MessageBatchStatus::Processing], true)) {
                return;
            }

            if (! $this->contactStillAllowed($recipient)) {
                $this->markSkipped($recipient, 'CONTACT_BECAME_INELIGIBLE', 'Contato ficou inapto apos a preparacao do lote.');

                return;
            }

            $settings = $this->settings->current();
            $window = $this->window->check($settings);
            if (! $window['allowed']) {
                $this->wait($recipient, MessageRecipientProcessingStatus::WaitingSchedule, $window['next_at'], $window['reason'] ?? 'Fora do horario permitido.');

                return;
            }

            $limit = $this->rateLimiter->check($settings);
            if (! $limit['allowed']) {
                $status = MessageRecipientProcessingStatus::from($limit['blocked_by'] ?? MessageRecipientProcessingStatus::WaitingMinuteLimit->value);
                $this->wait($recipient, $status, $limit['next_at'], 'Aguardando limite de envio.');

                return;
            }

            try {
                $provider = $this->providers->provider();
                $status = $provider->getStatus();

                if ($status->status !== WhatsAppConnectionStatus::Connected) {
                    if ($settings->pause_when_disconnected) {
                        $batch->forceFill([
                            'status' => MessageBatchStatus::Paused,
                            'paused_at' => now(),
                            'last_error_code' => 'WHATSAPP_DISCONNECTED',
                            'last_error_message' => 'WhatsApp desconectado durante o processamento.',
                        ])->save();
                        $this->events->record($batch, 'connection_lost', 'WhatsApp desconectado. Lote pausado.', $recipient, errorCode: 'WHATSAPP_DISCONNECTED');
                    }

                    $this->wait($recipient, MessageRecipientProcessingStatus::RetryWait, now()->addMinutes($settings->retry_interval_minutes), 'WhatsApp desconectado.');

                    return;
                }

                $attemptNumber = $recipient->attempts + 1;
                $recipient->forceFill([
                    'processing_status' => MessageRecipientProcessingStatus::Processing,
                    'attempts' => $attemptNumber,
                    'processing_started_at' => now(),
                    'last_attempt_at' => now(),
                ])->save();

                $attempt = MessageSendAttempt::create([
                    'message_batch_recipient_id' => $recipient->id,
                    'attempt_number' => $attemptNumber,
                    'request_id' => $recipient->request_id,
                    'status' => MessageSendAttemptStatus::Started,
                    'provider' => config('whatsapp.provider', 'web'),
                    'started_at' => now(),
                ]);

                $this->rateLimiter->consume($settings);
                $result = $provider->sendMessage((string) ($recipient->contact?->phone_normalized ?: preg_replace('/\D+/', '', $recipient->contact_phone_snapshot)), $recipient->rendered_message, $recipient->request_id);

                $attempt->forceFill([
                    'status' => $result->status === 'sent' ? MessageSendAttemptStatus::Sent : MessageSendAttemptStatus::Failed,
                    'finished_at' => now(),
                    'external_message_id' => $result->externalMessageId,
                    'error_code' => $result->errorCode,
                    'error_message' => $result->errorMessage,
                ])->save();

                if ($result->status === 'sent') {
                    $recipient->forceFill([
                        'processing_status' => MessageRecipientProcessingStatus::Sent,
                        'sent_at' => $result->sentAt ?? now(),
                        'external_message_id' => $result->externalMessageId,
                        'error_code' => null,
                        'error_message' => null,
                    ])->save();
                    $this->events->record($batch, 'recipient_sent', 'Mensagem enviada.', $recipient);
                } else {
                    $this->handleFailure($recipient, $result->errorCode ?: 'SEND_FAILED', $result->errorMessage ?: 'Falha no envio.');
                }
            } catch (WhatsAppServiceException $exception) {
                $this->recordException($recipient, $exception->errorCode, $exception->userMessage());
            } catch (Throwable $exception) {
                $this->recordException($recipient, 'TEMPORARY_PROVIDER_ERROR', 'Falha temporaria ao processar envio.');
            }

            $this->progress->completeIfFinished($batch);
            DispatchMessageBatchJob::dispatch($batch->id, $batch->processing_version)->onQueue('whatsapp-messages');
        });
    }

    private function contactStillAllowed(MessageBatchRecipient $recipient): bool
    {
        $contact = $recipient->contact;

        return $contact
            && $contact->status === ContactStatus::Active
            && ! $contact->do_not_contact
            && filled($contact->phone_normalized);
    }

    private function wait(MessageBatchRecipient $recipient, MessageRecipientProcessingStatus $status, mixed $nextAt, string $message): void
    {
        $recipient->forceFill([
            'processing_status' => $status,
            'retry_at' => $nextAt,
            'error_code' => strtoupper($status->value),
            'error_message' => $message,
        ])->save();

        $this->events->record($recipient->batch, 'recipient_waiting', $message, $recipient, errorCode: strtoupper($status->value));
        $this->progress->sync($recipient->batch);
    }

    private function markSkipped(MessageBatchRecipient $recipient, string $code, string $message): void
    {
        $recipient->forceFill([
            'processing_status' => MessageRecipientProcessingStatus::Skipped,
            'failed_at' => now(),
            'error_code' => $code,
            'error_message' => $message,
        ])->save();

        $this->events->record($recipient->batch, 'recipient_skipped', $message, $recipient, errorCode: $code);
        $this->progress->sync($recipient->batch);
    }

    private function recordException(MessageBatchRecipient $recipient, string $code, string $message): void
    {
        MessageSendAttempt::query()
            ->where('message_batch_recipient_id', $recipient->id)
            ->where('attempt_number', $recipient->attempts)
            ->where('status', MessageSendAttemptStatus::Started)
            ->latest()
            ->first()
            ?->forceFill([
                'status' => $code === 'SEND_RESULT_UNKNOWN' ? MessageSendAttemptStatus::Unknown : MessageSendAttemptStatus::Failed,
                'finished_at' => now(),
                'error_code' => $code,
                'error_message' => $message,
            ])->save();

        $this->handleFailure($recipient, $code, $message);
    }

    private function handleFailure(MessageBatchRecipient $recipient, string $code, string $message): void
    {
        $settings = $this->settings->current();

        if ($this->retryPolicy->canRetry($recipient, $code)) {
            $recipient->forceFill([
                'processing_status' => MessageRecipientProcessingStatus::RetryWait,
                'retry_at' => $this->retryPolicy->nextAttemptAt($recipient, $settings),
                'error_code' => $code,
                'error_message' => $message,
            ])->save();
            $this->events->record($recipient->batch, 'recipient_retry_scheduled', 'Nova tentativa agendada.', $recipient, errorCode: $code);
        } else {
            $recipient->forceFill([
                'processing_status' => $this->retryPolicy->isTemporary($code) ? MessageRecipientProcessingStatus::FailedTemporary : MessageRecipientProcessingStatus::FailedPermanent,
                'failed_at' => now(),
                'error_code' => $code,
                'error_message' => $message,
            ])->save();
            $this->events->record($recipient->batch, 'recipient_failed', 'Envio falhou.', $recipient, errorCode: $code);
        }

        Log::channel('message_processing')->info('recipient_processed', [
            'batch_id' => $recipient->message_batch_id,
            'recipient_id' => $recipient->id,
            'request_id' => $recipient->request_id,
            'status' => $recipient->processing_status?->value,
            'attempt' => $recipient->attempts,
            'error_code' => $code,
        ]);
    }
}
