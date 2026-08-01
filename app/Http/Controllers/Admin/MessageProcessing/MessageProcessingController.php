<?php

namespace App\Http\Controllers\Admin\MessageProcessing;

use App\Actions\MessageBatches\CancelMessageRecipientAction;
use App\Actions\MessageBatches\PauseMessageBatchAction;
use App\Actions\MessageBatches\ResumeMessageBatchAction;
use App\Actions\MessageBatches\ResumeStoppedMessageBatchAction;
use App\Actions\MessageBatches\RetryMessageRecipientAction;
use App\Actions\MessageBatches\UncancelMessageRecipientAction;
use App\Actions\MessageBatches\StartMessageBatchAction;
use App\Actions\MessageBatches\StopMessageBatchAction;
use App\Enums\MessageBatchStatus;
use App\Http\Controllers\Controller;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Services\MessageProcessing\BatchProgressService;
use App\Services\MessageProcessing\SendingRateLimiterService;
use App\Services\MessageProcessing\SendingSettingsService;
use App\Services\MessageProcessing\SendingWindowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

class MessageProcessingController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('message_processing.view'), 403);

        $batches = MessageBatch::query()
            ->whereIn('status', [
                MessageBatchStatus::Queued,
                MessageBatchStatus::Processing,
                MessageBatchStatus::Paused,
                MessageBatchStatus::Stopped,
                MessageBatchStatus::Completed,
                MessageBatchStatus::CompletedWithErrors,
                MessageBatchStatus::Failed,
            ])
            ->latest()
            ->paginate(20);

        return view('admin.message-processing.index', ['batches' => $batches]);
    }

    public function show(Request $request, MessageBatch $messageBatch, SendingSettingsService $settings, SendingWindowService $window, SendingRateLimiterService $rateLimiter, BatchProgressService $progress): View
    {
        abort_unless($request->user()->can('message_processing.view'), 403);

        $batch = $progress->sync($messageBatch);
        $currentSettings = $settings->current();
        $recipients = $batch->recipients()->with('contact')->paginate(20);

        return view('admin.message-processing.show', [
            'batch' => $batch,
            'recipients' => $recipients,
            // Com o destinatário junto: um evento sem nome so diz que algo
            // aconteceu, e a tela existe para saber com quem.
            'events' => $batch->processingEvents()->with('recipient')->limit(20)->get(),
            'settings' => $currentSettings,
            'window' => $window->check($currentSettings),
            'limits' => $rateLimiter->check($currentSettings),
            // Quantos voltariam se o lote fosse retomado. Vai para o rótulo do
            // botão: "Retomar envios" sem número esconde o tamanho do que vai
            // acontecer.
            'retomaveis' => $batch->status === MessageBatchStatus::Stopped
                ? ResumeStoppedMessageBatchAction::candidatos($batch)->count()
                : 0,
        ]);
    }

    public function start(Request $request, MessageBatch $messageBatch, StartMessageBatchAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('message_processing.start'), 403);

        return $this->run(fn () => $action->execute($messageBatch, $request->user()), 'Lote iniciado.');
    }

    public function pause(Request $request, MessageBatch $messageBatch, PauseMessageBatchAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('message_processing.pause'), 403);

        return $this->run(fn () => $action->execute($messageBatch, $request->user(), $request->string('reason')->toString()), 'Lote pausado.');
    }

    public function resume(Request $request, MessageBatch $messageBatch, ResumeMessageBatchAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('message_processing.resume'), 403);

        return $this->run(fn () => $action->execute($messageBatch, $request->user()), 'Lote retomado.');
    }

    public function stop(Request $request, MessageBatch $messageBatch, StopMessageBatchAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('message_processing.stop'), 403);
        $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->run(fn () => $action->execute($messageBatch, $request->user(), $request->string('reason')->toString()), 'Lote parado.');
    }

    /**
     * Retoma um lote que havia sido parado.
     *
     * Exige a permissão de iniciar, e não a de retomar: retomar um lote pausado
     * apenas destrava o que estava suspenso, enquanto isto rearma envios que
     * foram cancelados. Está mais perto de começar do que de continuar.
     */
    public function resumeStopped(Request $request, MessageBatch $messageBatch, ResumeStoppedMessageBatchAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('message_processing.start'), 403);

        try {
            $resumo = $action->execute($messageBatch, $request->user());
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $mensagem = "Lote retomado: {$resumo['retomados']} destinatário(s) voltaram para a fila.";

        if ($resumo['mantidos_fora'] > 0) {
            $mensagem .= " {$resumo['mantidos_fora']} ficaram de fora porque o contato não pode mais receber mensagem.";
        }

        return back()->with('success', $mensagem);
    }

    public function cancelRecipient(Request $request, MessageBatch $messageBatch, MessageBatchRecipient $recipient, CancelMessageRecipientAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('message_processing.cancel_recipient'), 403);
        abort_unless($recipient->message_batch_id === $messageBatch->id, 404);

        return $this->run(fn () => $action->execute($recipient, $request->user()), 'Destinatário cancelado.');
    }

    /**
     * Desfaz o cancelamento.
     *
     * Mesma permissão de cancelar: quem pode tirar alguém do envio pode
     * recolocar. Exigir permissão maior para desfazer tornaria o engano mais
     * caro de corrigir do que de cometer.
     */
    public function uncancelRecipient(Request $request, MessageBatch $messageBatch, MessageBatchRecipient $recipient, UncancelMessageRecipientAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('message_processing.cancel_recipient'), 403);
        abort_unless($recipient->message_batch_id === $messageBatch->id, 404);

        return $this->run(fn () => $action->execute($recipient, $request->user()), 'Cancelamento desfeito. O destinatário volta para a fila de espera.');
    }

    public function retryRecipient(Request $request, MessageBatch $messageBatch, MessageBatchRecipient $recipient, RetryMessageRecipientAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('message_processing.retry'), 403);
        abort_unless($recipient->message_batch_id === $messageBatch->id, 404);

        return $this->run(fn () => $action->execute($recipient, $request->user()), 'Nova tentativa agendada.');
    }

    public function attempts(Request $request, MessageBatch $messageBatch, MessageBatchRecipient $recipient): View
    {
        abort_unless($request->user()->can('message_processing.view_attempts'), 403);
        abort_unless($recipient->message_batch_id === $messageBatch->id, 404);

        return view('admin.message-processing.attempts', [
            'batch' => $messageBatch,
            'recipient' => $recipient,
            'attempts' => $recipient->attempts()->latest()->get(),
        ]);
    }

    private function run(callable $callback, string $message): RedirectResponse
    {
        try {
            $callback();

            return back()->with('success', $message);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
    }
}
