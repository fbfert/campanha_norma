<?php

namespace App\Actions\MessageBatches;

use App\Enums\MessageBatchStatus;
use App\Enums\MessageRecipientProcessingStatus;
use App\Jobs\DispatchMessageBatchJob;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MessageProcessing\BatchProgressService;
use App\Services\MessageProcessing\MessageProcessingEventService;
use RuntimeException;

/**
 * Retoma um lote que havia sido parado.
 *
 * Parar e destrutivo: cancela todo destinatário que ainda não tinha saído,
 * marcando `BATCH_STOPPED`. Era irreversível — quem parasse por engano, ou
 * parasse por um motivo que depois se resolveu, teria de montar o lote de novo.
 *
 * Retomar não e simplesmente religar o lote. E desfazer aqueles cancelamentos,
 * e por isso três distinções importam:
 *
 * 1. **Só volta quem a parada cancelou.** Um destinatário que alguém cancelou
 *    individualmente foi uma decisão de pessoa, tomada sobre aquele contato;
 *    uma ação de lote não pode desfazê-la por tabela. Por isso o filtro e pelo
 *    `error_code`, e não pela situação.
 *
 * 2. **Quem ficou inapto no meio-tempo continua de fora.** Entre a parada e a
 *    retomada alguém pode ter pedido para sair. Ressuscitar esse envio seria o
 *    pior erro possível desta tela.
 *
 * 3. **Quem já recebeu não recebe de novo.** Enviados não são tocados: eles não
 *    estão cancelados, então não entram no filtro.
 *
 * A versão de processamento e incrementada, como na retomada de um lote
 * pausado: trabalhos antigos que ainda estejam na fila carregam a versão
 * anterior e são descartados ao acordar.
 */
class ResumeStoppedMessageBatchAction
{
    public function __construct(
        private readonly BatchProgressService $progress,
        private readonly MessageProcessingEventService $events,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{retomados: int, mantidos_fora: int}
     */
    public function execute(MessageBatch $batch, User $user): array
    {
        if ($batch->status !== MessageBatchStatus::Stopped) {
            throw new RuntimeException('Somente um lote parado pode ser retomado por aqui.');
        }

        $candidatos = self::candidatos($batch)->with('contact')->get();

        if ($candidatos->isEmpty()) {
            throw new RuntimeException('Não há destinatário para retomar neste lote.');
        }

        [$aptos, $inaptos] = $candidatos->partition(
            static fn (MessageBatchRecipient $recipient): bool => $recipient->contactStillEligible()
        );

        if ($aptos->isEmpty()) {
            throw new RuntimeException(
                'Nenhum dos destinatários pode mais receber mensagem: todos estão marcados como '
                .'não contatar, inativos ou sem telefone válido.'
            );
        }

        // Só quem esta cancelado precisa ser mexido. Quem já estava em situação
        // de envio fica como esta: reescrever a situação dele apagaria a
        // informação de por que ele estava esperando.
        $paraDescancelar = $aptos
            ->where('processing_status', MessageRecipientProcessingStatus::Cancelled)
            ->pluck('id');

        if ($paraDescancelar->isNotEmpty()) {
            MessageBatchRecipient::query()
                ->whereIn('id', $paraDescancelar)
                ->update([
                    'processing_status' => MessageRecipientProcessingStatus::Pending->value,
                    'cancelled_at' => null,
                    'retry_at' => null,
                    'error_code' => null,
                    'error_message' => null,
                ]);
        }

        $batch->forceFill([
            'status' => MessageBatchStatus::Queued,
            'stopped_at' => null,
            'stop_requested_at' => null,
            'cancel_reason' => null,
            'cancelled_by' => null,
            'resume_requested_at' => now(),
            'next_dispatch_at' => now(),
            'processing_version' => $batch->processing_version + 1,
        ])->save();

        $resumo = ['retomados' => $aptos->count(), 'mantidos_fora' => $inaptos->count()];

        $this->events->record($batch, 'batch_resumed', 'Lote parado foi retomado.', user: $user, metadata: $resumo);
        $this->audit->log('message_batch.resumed_after_stop', 'Lote parado foi retomado.', $batch, null, $resumo, $user);

        $this->progress->sync($batch);

        DispatchMessageBatchJob::dispatch($batch->id, $batch->processing_version)->onQueue('whatsapp-messages');

        return $resumo;
    }

    /** Situações em que o destinatário ainda sairia, bastando o lote voltar a rodar. */
    private const AINDA_SAEM = [
        MessageRecipientProcessingStatus::Pending,
        MessageRecipientProcessingStatus::Queued,
        MessageRecipientProcessingStatus::RetryWait,
        MessageRecipientProcessingStatus::FailedTemporary,
        MessageRecipientProcessingStatus::WaitingSchedule,
        MessageRecipientProcessingStatus::WaitingMinuteLimit,
        MessageRecipientProcessingStatus::WaitingMinimumInterval,
        MessageRecipientProcessingStatus::WaitingHourLimit,
        MessageRecipientProcessingStatus::WaitingDayLimit,
    ];

    /**
     * Destinatários que voltam a sair quando o lote for retomado.
     *
     * São dois grupos, e a primeira versão disto enxergava só o primeiro:
     *
     * - **cancelados pela parada** (`BATCH_STOPPED`), que precisam ser
     *   descancelados;
     * - **os que já estão em situação de envio**, que não precisam de nada além
     *   de o lote voltar a rodar.
     *
     * O segundo grupo existe de verdade: basta alguém desfazer um cancelamento
     * individual com o lote parado, e o destinatário volta para `pending` sem a
     * marca `BATCH_STOPPED`. Filtrando só pela marca, o botão de retomar sumia
     * exatamente nesse caso — o lote ficava parado, com gente pronta para
     * receber, e sem caminho de volta pela tela.
     *
     * O que continua de fora e o cancelamento individual, que tem marca própria
     * e e decisão tomada sobre aquela pessoa.
     *
     * Fica público e estático porque a tela conta quantos são para escrever no
     * botão: "Retomar envios" sem número esconde o tamanho do que vai acontecer.
     *
     * @return \Illuminate\Database\Eloquent\Builder<MessageBatchRecipient>
     */
    public static function candidatos(MessageBatch $batch): \Illuminate\Database\Eloquent\Builder
    {
        return $batch->recipients()
            ->where(function ($query): void {
                $query
                    ->where(function ($cancelados): void {
                        $cancelados
                            ->where('processing_status', MessageRecipientProcessingStatus::Cancelled->value)
                            ->where('error_code', 'BATCH_STOPPED');
                    })
                    ->orWhereIn('processing_status', array_map(
                        static fn (MessageRecipientProcessingStatus $situacao): string => $situacao->value,
                        self::AINDA_SAEM
                    ));
            })
            ->getQuery();
    }
}
