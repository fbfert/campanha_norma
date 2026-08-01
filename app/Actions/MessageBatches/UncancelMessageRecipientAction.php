<?php

namespace App\Actions\MessageBatches;

use App\Enums\MessageBatchStatus;
use App\Enums\MessageRecipientProcessingStatus;
use App\Jobs\DispatchMessageBatchJob;
use App\Models\MessageBatchRecipient;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MessageProcessing\BatchProgressService;
use App\Services\MessageProcessing\MessageProcessingEventService;
use RuntimeException;

/**
 * Desfaz o cancelamento de um destinatário.
 *
 * Cancelar era irreversível: quem clicasse por engano — ou mudasse de ideia —
 * teria de refazer o lote inteiro para alcançar uma pessoa.
 *
 * O destinatário volta para `Pending`, e não direto para a fila. A diferença
 * importa: em `Pending` ele passa de novo por todas as conferências do envio —
 * elegibilidade do contato, janela de horário e limites de ritmo. Desfazer um
 * cancelamento devolve a pessoa à fila de espera, e não à frente dela.
 *
 * A recusa quando o contato ficou inapto e deliberada. Poderia deixar passar,
 * já que o envio conferiria de novo e marcaria como pulado — mas aí a pessoa
 * clicaria "descancelar", veria sucesso, e descobriria depois que nada saiu.
 * Dizer não na hora e mais honesto do que dizer sim e não cumprir.
 */
class UncancelMessageRecipientAction
{
    public function __construct(
        private readonly BatchProgressService $progress,
        private readonly MessageProcessingEventService $events,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(MessageBatchRecipient $recipient, User $user): MessageBatchRecipient
    {
        if ($recipient->processing_status !== MessageRecipientProcessingStatus::Cancelled) {
            throw new RuntimeException('Somente um destinatário cancelado pode ser descancelado.');
        }

        if (! $recipient->contactStillEligible()) {
            throw new RuntimeException(
                'Este contato não pode mais receber mensagem: está marcado como não contatar, '
                .'inativo ou sem telefone válido.'
            );
        }

        $recipient->forceFill([
            'processing_status' => MessageRecipientProcessingStatus::Pending,
            'cancelled_at' => null,
            'retry_at' => null,
            'error_code' => null,
            'error_message' => null,
        ])->save();

        $batch = $recipient->batch;

        $this->events->record($batch, 'recipient_uncancelled', 'Cancelamento desfeito.', $recipient, $user);
        $this->audit->log('message_recipient.uncancelled', 'Cancelamento de destinatário desfeito.', $recipient, null, ['batch_id' => $batch->id], $user);
        $this->progress->sync($batch);

        // Só empurra o lote se ele já estiver rodando. Desfazer um cancelamento
        // num lote pausado não pode fazer o lote voltar a enviar sozinho — quem
        // pausou decide quando retomar.
        if (in_array($batch->status, [MessageBatchStatus::Processing, MessageBatchStatus::Queued], true)) {
            DispatchMessageBatchJob::dispatch($batch->id, $batch->processing_version)->onQueue('whatsapp-messages');
        }

        return $recipient->refresh();
    }
}
