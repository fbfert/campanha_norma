<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\PreparedChunk;
use App\Enums\KnowledgeDocumentStatus;
use App\Enums\RetrievalStrategy;
use App\Exceptions\Knowledge\KnowledgeProviderException;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Extrai, sanitiza, divide, gera embeddings e indexa.
 *
 * O resultado de sucesso e `ready`, não `approved`: indexar e técnico, aprovar e
 * uma afirmação humana. Nenhum caminho aqui torna um documento recuperável.
 */
class KnowledgeIndexingService
{
    public function __construct(
        private readonly DocumentTextExtractor $extractor,
        private readonly PromptInjectionSanitizer $sanitizer,
        private readonly DocumentChunker $chunker,
        private readonly KnowledgeProviderManager $providers,
        private readonly KnowledgeGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function index(KnowledgeDocument $document): KnowledgeDocument
    {
        $document->forceFill([
            'status' => KnowledgeDocumentStatus::Processing,
            'error_message' => null,
        ])->save();

        try {
            $extracted = $this->extractor->extract($document);

            $sanitized = $this->sanitizer->sanitize($extracted->text);
            $pages = [];

            foreach ($extracted->pages as $number => $pageText) {
                $pages[$number] = $this->sanitizer->sanitize($pageText)['text'];
            }

            $prepared = $this->chunker->chunk(
                new Extractors\ExtractedText($sanitized['text'], $pages)
            );

            if ($prepared === []) {
                throw KnowledgeProviderException::code(KnowledgeProviderException::EMPTY_EXTRACTION);
            }

            $prepared = $this->withEmbeddings($prepared);

            $result = $this->providers->provider()->indexDocument($document, $prepared);

            $document->forceFill([
                // Documento reindexado volta a aguardar aprovação: o texto mudou,
                // e quem aprovou o anterior não aprovou este.
                'status' => KnowledgeDocumentStatus::Ready,
                'extracted_text' => $sanitized['text'],
                'chunk_count' => $result->indexedChunks,
                'indexed_at' => now(),
                'provider_file_id' => $result->providerFileId,
                'injection_flagged' => $sanitized['flagged'],
                'injection_findings' => $sanitized['findings'] === [] ? null : implode("\n", $sanitized['findings']),
                'error_message' => null,
                'approved_by' => null,
                'approved_at' => null,
            ])->save();

            if ($sanitized['flagged']) {
                Log::warning('knowledge.injection_detected', [
                    'document_id' => $document->id,
                    'knowledge_base_id' => $document->knowledge_base_id,
                    'findings' => count($sanitized['findings']),
                ]);
            }

            $this->audit->log('knowledge_document.indexed', 'Documento indexado.', $document, null, [
                'chunks' => $result->indexedChunks,
                'injection_flagged' => $sanitized['flagged'],
            ]);

            return $document->refresh();
        } catch (KnowledgeProviderException $exception) {
            $document->forceFill([
                'status' => KnowledgeDocumentStatus::Failed,
                'error_message' => mb_substr($exception->errorCode, 0, 255),
            ])->save();

            Log::warning('knowledge.indexing_failed', [
                'document_id' => $document->id,
                'error_code' => $exception->errorCode,
                'detail' => $exception->providerDetail,
            ]);

            throw $exception;
        }
    }

    /**
     * Aprovação humana: e o único caminho que torna um documento recuperável.
     */
    public function approve(KnowledgeDocument $document, User $user): KnowledgeDocument
    {
        $document->forceFill([
            'status' => KnowledgeDocumentStatus::Approved,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ])->save();

        // Aprovar a nova versão aposenta a anterior sem apagar nada dela.
        if ($document->supersedes_document_id !== null) {
            $previous = KnowledgeDocument::find($document->supersedes_document_id);

            if ($previous && $previous->status !== KnowledgeDocumentStatus::Obsolete) {
                $this->obsolete($previous, $user, 'substituído pela versão '.$document->version);
            }
        }

        $this->audit->log('knowledge_document.approved', 'Documento aprovado para uso.', $document, null, [
            'knowledge_base_id' => $document->knowledge_base_id,
            'version' => $document->version,
        ], $user);

        return $document->refresh();
    }

    public function reject(KnowledgeDocument $document, User $user, ?string $reason = null): KnowledgeDocument
    {
        $document->forceFill([
            'status' => KnowledgeDocumentStatus::Rejected,
            'rejected_by' => $user->id,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
            'approved_by' => null,
            'approved_at' => null,
        ])->save();

        $this->audit->log('knowledge_document.rejected', 'Documento rejeitado.', $document, null, [
            'reason' => $reason,
        ], $user);

        return $document->refresh();
    }

    public function obsolete(KnowledgeDocument $document, User $user, ?string $reason = null): KnowledgeDocument
    {
        $document->forceFill([
            'status' => KnowledgeDocumentStatus::Obsolete,
            'obsoleted_by' => $user->id,
            'obsoleted_at' => now(),
        ])->save();

        $this->audit->log('knowledge_document.obsoleted', 'Documento marcado como obsoleto.', $document, null, [
            'reason' => $reason,
        ], $user);

        return $document->refresh();
    }

    /**
     * Exclusão definitiva. Sincroniza o provedor e apaga o arquivo privado, mas
     * não toca no log de recuperação nem nas citações: elas guardam snapshot
     * justamente para sobreviver a isto.
     */
    public function delete(KnowledgeDocument $document, ?User $user = null): void
    {
        $this->providers->provider()->deleteDocument($document);

        if (Storage::disk($document->disk)->exists($document->file_path)) {
            Storage::disk($document->disk)->delete($document->file_path);
        }

        $this->audit->log('knowledge_document.deleted', 'Documento excluído da base de conhecimento.', $document, [
            'title' => $document->title,
            'knowledge_base_id' => $document->knowledge_base_id,
        ], null, $user);

        $document->delete();
    }

    /**
     * Embeddings apenas quando a estratégia ativa os usa. Falta de credencial não
     * impede a indexação: a estratégia léxica não depende de vetor.
     *
     * @param  array<int, PreparedChunk>  $chunks
     * @return array<int, PreparedChunk>
     */
    private function withEmbeddings(array $chunks): array
    {
        if (! $this->guard->strategy()->usesEmbeddings()) {
            return $chunks;
        }

        $embeddings = $this->providers->embeddings();

        if (! $embeddings->isConfigured()) {
            if ($this->guard->strategy() === RetrievalStrategy::Vector) {
                throw KnowledgeProviderException::code(KnowledgeProviderException::EMBEDDINGS_NOT_CONFIGURED);
            }

            // Na estratégia híbrida a parte léxica continua funcionando, então
            // indexar sem vetor e degradação aceitável e registrada.
            Log::warning('knowledge.embeddings_unavailable', ['strategy' => $this->guard->strategy()->value]);

            return $chunks;
        }

        $vectors = $embeddings->embed(array_map(fn (PreparedChunk $c): string => $c->content, $chunks));

        return array_map(
            fn (PreparedChunk $chunk, int $position): PreparedChunk => $chunk->withEmbedding($vectors[$position]),
            $chunks,
            array_keys($chunks),
        );
    }
}
