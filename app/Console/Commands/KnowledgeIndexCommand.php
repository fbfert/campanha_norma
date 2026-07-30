<?php

namespace App\Console\Commands;

use App\Enums\KnowledgeDocumentStatus;
use App\Jobs\IndexKnowledgeDocumentJob;
use App\Models\KnowledgeDocument;
use App\Services\SystemSettingService;
use Illuminate\Console\Command;

/**
 * Reindexação de documentos.
 *
 * Enfileira, nunca indexa em linha: extração e embedding são trabalho de fila, e
 * um comando que os executa no processo do operador esconde o custo real.
 *
 * Reindexar revoga a aprovação. E intencional: conteúdo novo precisa de leitura
 * humana nova.
 */
class KnowledgeIndexCommand extends Command
{
    protected $signature = 'knowledge:index
        {--base= : Limita a uma base pelo id}
        {--document= : Indexa um documento específico}
        {--failed : Somente documentos que falharam}
        {--dry-run : Apenas lista o que seria enfileirado}';

    protected $description = 'Enfileira a indexação de documentos da base de conhecimento.';

    public function handle(SystemSettingService $settings): int
    {
        $query = KnowledgeDocument::query()
            ->when($this->option('base'), fn ($q) => $q->where('knowledge_base_id', (int) $this->option('base')))
            ->when($this->option('document'), fn ($q) => $q->whereKey((int) $this->option('document')))
            ->when($this->option('failed'), fn ($q) => $q->where('status', KnowledgeDocumentStatus::Failed->value));

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('Nenhum documento corresponde aos filtros.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line("{$total} documento(s) seriam enfileirados.");

            return self::SUCCESS;
        }

        $queue = (string) $settings->get('knowledge.queue', 'knowledge-indexing');
        $enqueued = 0;

        $query->select(['id', 'status'])->chunkById(200, function ($documents) use ($queue, &$enqueued): void {
            foreach ($documents as $document) {
                $document->update(['status' => KnowledgeDocumentStatus::Processing, 'error_message' => null]);
                IndexKnowledgeDocumentJob::dispatch($document->id)->onQueue($queue);
                $enqueued++;
            }
        });

        $this->info("{$enqueued} documento(s) enfileirados na fila {$queue}.");
        $this->warn('A aprovação anterior de cada documento será revogada ao fim da indexação.');

        return self::SUCCESS;
    }
}
