<?php

namespace App\Console\Commands;

use App\Enums\KnowledgeDocumentStatus;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Reconciliacao entre o estado gravado e o armazenamento efetivo.
 *
 * Nunca aprova, nunca reindexa e nunca apaga documento. Corrige contagem,
 * remove trecho orfao e sinaliza divergencia que exige decisao humana. Um
 * comando de manutencao que decide por conta propria o que e conteudo oficial
 * seria uma porta lateral para a aprovacao.
 */
class KnowledgeSyncCommand extends Command
{
    protected $signature = 'knowledge:sync
        {--base= : Limita a uma base pelo id}
        {--dry-run : Apenas relata as divergencias}';

    protected $description = 'Reconcilia contagem de trechos, trechos orfaos e arquivos ausentes da base de conhecimento.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $baseId = $this->option('base') ? (int) $this->option('base') : null;

        $fixedCounts = $this->reconcileChunkCounts($baseId, $dryRun);
        $orphans = $this->removeOrphanChunks($dryRun);
        $missing = $this->reportMissingFiles($baseId);
        $stuck = $this->reportStuckDocuments($baseId);

        $this->newLine();
        $this->info(($dryRun ? 'Divergencias encontradas' : 'Reconciliacao concluida').':');
        $this->line("- contagem de trechos corrigida: {$fixedCounts}");
        $this->line("- trechos orfaos removidos: {$orphans}");
        $this->line("- documentos com arquivo ausente: {$missing}");
        $this->line("- documentos parados em processamento: {$stuck}");

        if ($missing > 0 || $stuck > 0) {
            $this->warn('Estes dois itens exigem decisao humana e nao sao corrigidos automaticamente.');
        }

        return self::SUCCESS;
    }

    private function reconcileChunkCounts(?int $baseId, bool $dryRun): int
    {
        $fixed = 0;

        KnowledgeDocument::query()
            ->when($baseId, fn ($q) => $q->where('knowledge_base_id', $baseId))
            ->select(['id', 'chunk_count'])
            ->chunkById(200, function ($documents) use (&$fixed, $dryRun): void {
                foreach ($documents as $document) {
                    $real = KnowledgeChunk::query()->where('knowledge_document_id', $document->id)->count();

                    if ($real === (int) $document->chunk_count) {
                        continue;
                    }

                    $this->line("documento {$document->id}: chunk_count {$document->chunk_count} -> {$real}");
                    $fixed++;

                    if (! $dryRun) {
                        $document->update(['chunk_count' => $real]);
                    }
                }
            });

        return $fixed;
    }

    private function removeOrphanChunks(bool $dryRun): int
    {
        $query = KnowledgeChunk::query()
            ->whereNotIn('knowledge_document_id', KnowledgeDocument::query()->select('id'));

        $total = (clone $query)->count();

        if ($total > 0 && ! $dryRun) {
            $query->delete();
        }

        return $total;
    }

    private function reportMissingFiles(?int $baseId): int
    {
        $missing = 0;

        KnowledgeDocument::query()
            ->when($baseId, fn ($q) => $q->where('knowledge_base_id', $baseId))
            ->whereNotNull('file_path')
            ->select(['id', 'disk', 'file_path', 'title'])
            ->chunkById(200, function ($documents) use (&$missing): void {
                foreach ($documents as $document) {
                    if (Storage::disk($document->disk)->exists($document->file_path)) {
                        continue;
                    }

                    $this->warn("documento {$document->id} ({$document->title}): arquivo ausente no disco {$document->disk}");
                    $missing++;
                }
            });

        return $missing;
    }

    /**
     * Documento preso em `processing` significa job perdido: quem decide entre
     * reprocessar e descartar e uma pessoa, nao este comando.
     */
    private function reportStuckDocuments(?int $baseId): int
    {
        $stuck = KnowledgeDocument::query()
            ->when($baseId, fn ($q) => $q->where('knowledge_base_id', $baseId))
            ->where('status', KnowledgeDocumentStatus::Processing->value)
            ->where('updated_at', '<', now()->subHours(2))
            ->get(['id', 'title']);

        foreach ($stuck as $document) {
            $this->warn("documento {$document->id} ({$document->title}): parado em processamento ha mais de 2 horas. Use knowledge:index --document={$document->id}.");
        }

        return $stuck->count();
    }
}
