<?php

namespace App\Console\Commands;

use App\Enums\KnowledgeDocumentStatus;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Knowledge\AntivirusScanner;
use App\Services\Knowledge\KnowledgeGuard;
use App\Services\Knowledge\KnowledgeProviderManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Diagnostico da camada de conhecimento.
 *
 * Nao chama o provedor de embeddings nem executa o antivirus sobre nenhum
 * arquivo: verifica configuracao e estado. Diagnostico que gasta cota ou tempo
 * de scanner deixa de ser rodado justamente quando e necessario.
 */
class KnowledgeDiagnoseCommand extends Command
{
    protected $signature = 'knowledge:diagnose {--base= : Limita a uma base pelo id}';

    protected $description = 'Verifica configuracao, provedores e estado da base de conhecimento.';

    public function handle(
        KnowledgeGuard $guard,
        KnowledgeProviderManager $providers,
        AntivirusScanner $antivirus,
    ): int {
        $this->line('== Configuracao ==');
        $this->line('Recuperacao ligada: '.($guard->enabled() ? 'sim' : 'nao'));
        $this->line('Estrategia: '.$guard->strategy()->value);
        $this->line('top_k: '.$guard->topK().' | threshold: '.$guard->threshold().' | contexto: '.$guard->maxContextChars().' caracteres');
        $this->line('Citacoes visiveis ao contato: '.($guard->showCitationsToContact() ? 'sim' : 'nao'));

        $this->newLine();
        $this->line('== Provedores ==');
        $provider = $providers->provider();
        $embeddings = $providers->embeddings();
        $this->line('Armazenamento: '.$provider->name().' | configurado: '.($provider->isConfigured() ? 'sim' : 'nao'));
        $this->line('Embeddings: '.$embeddings->name().' | configurado: '.($embeddings->isConfigured() ? 'sim' : 'nao'));

        if ($guard->strategy()->usesEmbeddings() && ! $embeddings->isConfigured()) {
            $this->warn('A estrategia configurada usa vetores, mas nao ha provedor de embeddings configurado.');
        }

        $this->newLine();
        $this->line('== Ferramentas externas ==');
        $this->line('Antivirus exigido: '.($antivirus->required() ? 'sim' : 'nao'));
        $this->line('Antivirus disponivel: '.($antivirus->available() ? 'sim' : 'nao'));

        if ($antivirus->required() && ! $antivirus->available()) {
            $this->warn('Antivirus exigido e ausente: todo upload sera recusado.');
        }

        $pdfCommand = trim((string) config('knowledge.pdf_text_command'));
        $this->line('Extrator de PDF: '.($pdfCommand === '' ? 'nao configurado' : $pdfCommand));

        $this->newLine();
        $this->line('== Estado ==');

        $bases = KnowledgeBase::query()
            ->when($this->option('base'), fn ($q) => $q->whereKey((int) $this->option('base')))
            ->orderBy('name')
            ->get();

        if ($bases->isEmpty()) {
            $this->warn('Nenhuma base cadastrada.');

            return self::SUCCESS;
        }

        $rows = [];
        $missingFiles = 0;

        foreach ($bases as $base) {
            $documents = KnowledgeDocument::query()->where('knowledge_base_id', $base->id)->get();

            foreach ($documents as $document) {
                if ($document->file_path && ! Storage::disk($document->disk)->exists($document->file_path)) {
                    $missingFiles++;
                }
            }

            $rows[] = [
                $base->name,
                $base->status->value,
                $base->flows()->count(),
                $documents->count(),
                $documents->where('status', KnowledgeDocumentStatus::Approved)->count(),
                $documents->where('status', KnowledgeDocumentStatus::Failed)->count(),
                KnowledgeChunk::query()->where('knowledge_base_id', $base->id)->count(),
            ];
        }

        $this->table(['Base', 'Situacao', 'Fluxos', 'Documentos', 'Aprovados', 'Falhos', 'Trechos'], $rows);

        if ($missingFiles > 0) {
            $this->warn("{$missingFiles} documento(s) com arquivo ausente no disco.");
        }

        $orphanChunks = KnowledgeChunk::query()
            ->whereNotIn('knowledge_document_id', KnowledgeDocument::query()->select('id'))
            ->count();

        if ($orphanChunks > 0) {
            $this->warn("{$orphanChunks} trecho(s) sem documento correspondente.");
        }

        return self::SUCCESS;
    }
}
