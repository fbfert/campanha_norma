<?php

namespace App\Jobs;

use App\Enums\KnowledgeDocumentStatus;
use App\Exceptions\Knowledge\KnowledgeProviderException;
use App\Models\KnowledgeDocument;
use App\Services\Knowledge\KnowledgeIndexingService;
use App\Services\SystemSettingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Indexa um documento em fila própria.
 *
 * A fila e separada de propósito: um PDF de duzentas páginas nunca deve atrasar
 * o registro de mensagens recebidas, a automação determinística, a interpretação
 * ou a geração de respostas.
 */
class IndexKnowledgeDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(private readonly int $documentId)
    {
        $this->onQueue(app(SystemSettingService::class)->get('knowledge.queue', 'knowledge-indexing'));
    }

    public function handle(KnowledgeIndexingService $indexing): void
    {
        $document = KnowledgeDocument::with('base')->find($this->documentId);

        if (! $document) {
            return;
        }

        // Uma trava por documento: reprocessar duas vezes em paralelo produziria
        // duas reconstruções de trecho concorrentes sobre a mesma linha.
        $lock = Cache::lock("knowledge-indexing:{$document->id}", 600);

        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $indexing->index($document);
        } catch (KnowledgeProviderException $exception) {
            // Falha não retentável encerra aqui: o documento já foi marcado como
            // `failed` com código, e repetir não muda o resultado.
            if (! $exception->isRetryable()) {
                return;
            }

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $document = KnowledgeDocument::find($this->documentId);

        if ($document && $document->status === KnowledgeDocumentStatus::Processing) {
            // Documento nunca fica preso em `processing` por morte do job.
            $document->forceFill([
                'status' => KnowledgeDocumentStatus::Failed,
                'error_message' => 'falha_na_fila',
            ])->save();
        }
    }
}
