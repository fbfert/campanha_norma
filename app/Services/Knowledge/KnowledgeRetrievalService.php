<?php

namespace App\Services\Knowledge;

use App\Contracts\KnowledgeRetriever;
use App\Data\Knowledge\RetrievalQuery;
use App\Data\Knowledge\RetrievalResult;
use App\Data\Knowledge\RetrievedChunk;
use App\Models\ConversationFlow;
use App\Models\KnowledgeRetrieval;
use App\Models\KnowledgeRetrievalChunk;
use Illuminate\Support\Facades\Log;

/**
 * Executa a recuperação e registra o que foi buscado e devolvido.
 *
 * Cada trecho retornado e gravado com snapshot de conteúdo, título e versão. E
 * duplicação de texto de propósito: sem ela, excluir um documento apagaria a
 * explicação de toda resposta que ele sustentou.
 */
class KnowledgeRetrievalService
{
    public function __construct(
        private readonly KnowledgeRetriever $retriever,
        private readonly KnowledgeGuard $guard,
        private readonly KnowledgeProviderManager $providers,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array{result: RetrievalResult, retrieval: ?KnowledgeRetrieval}
     */
    public function retrieveForFlow(?ConversationFlow $flow, string $text, array $context = [], bool $isTest = false): array
    {
        $baseIds = $this->guard->baseIdsForFlow($flow);

        $query = new RetrievalQuery(
            text: $text,
            baseIds: $baseIds,
            strategy: $this->guard->strategy(),
            topK: $this->guard->topK(),
            threshold: $this->guard->threshold(),
            maxContextChars: $this->guard->maxContextChars(),
        );

        if ($baseIds === []) {
            // Sem base associada não ha o que registrar: nenhuma busca aconteceu.
            return ['result' => RetrievalResult::empty($query->strategy, 'sem_base_associada'), 'retrieval' => null];
        }

        $result = $this->retriever->retrieve($query);
        $retrieval = $this->log($query, $result, $flow, $context, $isTest);

        return ['result' => $result, 'retrieval' => $retrieval];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(
        RetrievalQuery $query,
        RetrievalResult $result,
        ?ConversationFlow $flow,
        array $context,
        bool $isTest,
    ): KnowledgeRetrieval {
        $retrieval = KnowledgeRetrieval::create([
            'conversation_id' => $context['conversation_id'] ?? null,
            'source_message_id' => $context['source_message_id'] ?? null,
            'conversation_flow_id' => $flow?->id,
            'ai_run_id' => $context['ai_run_id'] ?? null,
            // Consulta truncada: auditar o que foi buscado não exige guardar a
            // mensagem inteira da pessoa em uma segunda tabela.
            'query_text' => mb_substr($query->text, 0, 1000),
            'strategy' => $result->strategy,
            'top_k' => $query->topK,
            'threshold' => $query->threshold,
            'candidate_count' => $result->candidateCount,
            'returned_count' => $result->count(),
            'max_score' => $result->maxScore(),
            'min_score' => $result->minScore(),
            'duration_ms' => $result->durationMs,
            'provider' => $this->providers->provider()->name(),
            'degraded_reason' => $result->degradedReason,
            'is_test' => $isTest,
        ]);

        foreach ($result->chunks as $position => $chunk) {
            $this->logChunk($retrieval, $chunk, $position);
        }

        Log::info('knowledge.retrieved', [
            'retrieval_id' => $retrieval->id,
            'strategy' => $result->strategy->value,
            'candidates' => $result->candidateCount,
            'returned' => $result->count(),
            'duration_ms' => $result->durationMs,
            'degraded_reason' => $result->degradedReason,
        ]);

        return $retrieval;
    }

    private function logChunk(KnowledgeRetrieval $retrieval, RetrievedChunk $chunk, int $position): void
    {
        KnowledgeRetrievalChunk::create([
            'knowledge_retrieval_id' => $retrieval->id,
            'knowledge_chunk_id' => $chunk->chunkId,
            'knowledge_document_id' => $chunk->documentId,
            'document_title_snapshot' => $chunk->documentTitle,
            'document_version' => $chunk->documentVersion,
            'chunk_reference' => $chunk->reference(),
            'content_snapshot' => $chunk->content,
            'page' => $chunk->page,
            'section' => $chunk->section,
            'score' => $chunk->score,
            'position' => $position,
        ]);
    }
}
