<?php

namespace App\Actions\MessageBatches;

use App\Enums\MessageBatchStatus;
use App\Enums\MessageRecipientProcessingStatus;
use App\Jobs\DispatchMessageBatchJob;
use App\Models\MessageBatchRecipient;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MessageProcessing\MessageProcessingEventService;
use RuntimeException;

/**
 * Reprocessa um destinatário agora.
 *
 * Vale para falha temporária e para os estados de espera: fora do horário
 * permitido, limite por minuto, por hora e por dia. Antes só a falha temporária
 * era aceita, e quem via um destinatário parado em "fora do horário" não tinha
 * nenhum botão — só esperar o worker olhar de novo.
 *
 * **Reprocessar reavalia, não força.** O destinatário volta para `Pending` e
 * refaz todas as conferências: elegibilidade do contato, janela e limites. Se a
 * janela ainda estiver fechada, ele volta para a espera — e isso e o
 * comportamento certo, não uma falha do botão. A janela existe para não mandar
 * mensagem de madrugada, e um botão na tela não e motivo para furá-la.
 *
 * O que o botão resolve de verdade e o caso em que a regra já não se aplica
 * mais: a janela reabriu, o limite do minuto passou, o número foi corrigido.
 * Sem ele, a pessoa espera o próximo ciclo sem saber quando ele vem.
 */
class RetryMessageRecipientAction
{
    /** Estados em que reprocessar faz sentido. */
    private const REPROCESSABLE = [
        MessageRecipientProcessingStatus::FailedTemporary,
        MessageRecipientProcessingStatus::WaitingSchedule,
        MessageRecipientProcessingStatus::WaitingMinuteLimit,
        MessageRecipientProcessingStatus::WaitingMinimumInterval,
        MessageRecipientProcessingStatus::WaitingHourLimit,
        MessageRecipientProcessingStatus::WaitingDayLimit,
        MessageRecipientProcessingStatus::RetryWait,
    ];

    public function __construct(private readonly MessageProcessingEventService $events, private readonly AuditLogger $audit) {}

    public function execute(MessageBatchRecipient $recipient, User $user): MessageBatchRecipient
    {
        if (! in_array($recipient->processing_status, self::REPROCESSABLE, true)) {
            throw new RuntimeException('Este destinatário não pode ser reprocessado no estado atual.');
        }

        // Mesma recusa do descancelamento: se o contato ficou inapto, dizer não
        // agora e melhor do que agendar algo que será pulado depois.
        if (! $recipient->contactStillEligible()) {
            throw new RuntimeException(
                'Este contato não pode mais receber mensagem: está marcado como não contatar, '
                .'inativo ou sem telefone válido.'
            );
        }

        $recipient->forceFill([
            'processing_status' => MessageRecipientProcessingStatus::Pending,
            'retry_at' => now(),
            'error_code' => null,
            'error_message' => null,
        ])->save();

        $batch = $recipient->batch;
        $this->events->record($batch, 'recipient_retry_scheduled', 'Reprocessamento solicitado.', $recipient, $user);
        $this->audit->log('message_recipient.retry_requested', 'Reprocessamento de destinatário solicitado.', $recipient, null, ['batch_id' => $batch->id], $user);

        // Num lote pausado, reprocessar prepara o destinatário mas não retoma o
        // lote: quem pausou decide quando voltar a enviar.
        if (in_array($batch->status, [MessageBatchStatus::Processing, MessageBatchStatus::Queued], true)) {
            DispatchMessageBatchJob::dispatch($batch->id, $batch->processing_version)->onQueue('whatsapp-messages');
        }

        return $recipient->refresh();
    }
}
