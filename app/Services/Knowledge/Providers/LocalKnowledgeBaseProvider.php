<?php

namespace App\Services\Knowledge\Providers;

use App\Contracts\KnowledgeBaseProvider;
use App\Data\Knowledge\PreparedChunk;
use App\Data\Knowledge\ProviderIndexResult;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Knowledge\TextNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Armazenamento relacional dos trechos e embeddings.
 *
 * A justificativa tecnica, os limites medidos e o gatilho de migracao estao em
 * `docs/adr/0001-armazenamento-vetorial-e-provedor-de-conhecimento.md`. Em
 * resumo: o corpus admissivel e curado e pequeno, e nessa faixa um segundo banco
 * de dados custa mais em operacao do que economiza em consulta.
 *
 * Nao existe armazenamento remoto: `external_chunk_id` recebe a chave local,
 * para que a citacao continue resolvivel apos uma futura troca de provedor.
 */
class LocalKnowledgeBaseProvider implements KnowledgeBaseProvider
{
    public function __construct(private readonly TextNormalizer $normalizer) {}

    public function name(): string
    {
        return 'local';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supportsRemoteStore(): bool
    {
        return false;
    }

    public function createStore(KnowledgeBase $base): ?string
    {
        return null;
    }

    public function deleteStore(KnowledgeBase $base): void
    {
        // Os trechos saem por cascata da propria base.
    }

    /**
     * @param  array<int, PreparedChunk>  $chunks
     */
    public function indexDocument(KnowledgeDocument $document, array $chunks): ProviderIndexResult
    {
        $ids = [];

        DB::transaction(function () use ($document, $chunks, &$ids): void {
            // Reindexar substitui: trecho antigo do mesmo documento nao pode
            // sobreviver a uma nova extracao e continuar sendo recuperado.
            KnowledgeChunk::query()->where('knowledge_document_id', $document->id)->delete();

            foreach ($chunks as $chunk) {
                $model = KnowledgeChunk::create([
                    'knowledge_document_id' => $document->id,
                    'knowledge_base_id' => $document->knowledge_base_id,
                    'chunk_index' => $chunk->index,
                    'content' => $chunk->content,
                    'search_text' => $this->normalizer->normalize($chunk->content),
                    'content_hash' => $chunk->hash(),
                    'token_estimate' => $chunk->tokenEstimate(),
                    'page' => $chunk->page,
                    'section' => $chunk->section,
                    'embedding' => $chunk->embedding === null ? null : KnowledgeChunk::packEmbedding($chunk->embedding),
                    'embedding_provider' => $chunk->embedding === null ? null : (string) config('knowledge.embeddings.provider'),
                    'embedding_model' => $chunk->embedding === null ? null : (string) config('knowledge.embeddings.openai.model'),
                    'embedding_dimensions' => $chunk->embedding === null ? null : count($chunk->embedding),
                    'embedded_at' => $chunk->embedding === null ? null : now(),
                ]);

                $model->forceFill(['external_chunk_id' => (string) $model->id])->save();

                $ids[] = (string) $model->id;
            }
        });

        return new ProviderIndexResult(
            indexedChunks: count($ids),
            providerFileId: null,
            externalChunkIds: $ids,
        );
    }

    public function deleteDocument(KnowledgeDocument $document): void
    {
        KnowledgeChunk::query()->where('knowledge_document_id', $document->id)->delete();
    }

    /** @return array<string, mixed> */
    public function health(): array
    {
        return [
            'provider' => 'local',
            'configured' => true,
            'healthy' => true,
            'chunks' => KnowledgeChunk::query()->count(),
            'chunks_with_embedding' => KnowledgeChunk::query()->whereNotNull('embedding')->count(),
        ];
    }
}
