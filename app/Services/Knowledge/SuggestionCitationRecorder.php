<?php

namespace App\Services\Knowledge;

use App\Data\Knowledge\GroundingVerdict;
use App\Models\ConversationReplySuggestion;
use App\Models\KnowledgeRetrieval;
use App\Models\KnowledgeRetrievalChunk;
use App\Models\ReplySuggestionCitation;

/**
 * Grava as fontes de uma sugestao fundamentada.
 *
 * A citacao guarda snapshot de titulo, versao e conteudo. E duplicacao proposital:
 * a explicacao de uma resposta ja enviada nao pode deixar de existir porque o
 * documento foi substituido ou removido depois.
 *
 * Citacao recusada tambem vira linha, com o motivo e sem vinculo de documento: o
 * identificador que o modelo inventou nao aponta para nada, e forcar uma chave
 * estrangeira nele transformaria auditoria em erro de integridade.
 */
class SuggestionCitationRecorder
{
    /**
     * @param  array<int, array<string, mixed>>  $declared  citacoes cruas devolvidas pelo modelo
     * @return int quantidade de citacoes validas gravadas
     */
    public function record(
        ConversationReplySuggestion $suggestion,
        GroundingVerdict $verdict,
        ?KnowledgeRetrieval $retrieval,
        array $declared = [],
    ): int {
        $chunkRows = $this->retrievalChunkIds($retrieval);
        $validReferences = [];

        foreach ($verdict->citations as $citation) {
            $reference = (string) ($citation['chunk_reference'] ?? '');
            $validReferences[] = $reference;

            ReplySuggestionCitation::create([
                'conversation_reply_suggestion_id' => $suggestion->id,
                'knowledge_retrieval_chunk_id' => $chunkRows[$reference] ?? null,
                'knowledge_document_id' => $citation['document_id'] ?? null,
                'document_title_snapshot' => $citation['document_title'] ?? null,
                'document_version' => $citation['document_version'] ?? null,
                'chunk_reference' => $reference,
                'content_snapshot' => $citation['content'] ?? null,
                'page' => $citation['page'] ?? null,
                'section' => $citation['section'] ?? null,
                'score' => $citation['score'] ?? null,
                'is_valid' => true,
            ]);
        }

        $this->recordRejected($suggestion, $declared, $validReferences);

        return count($verdict->citations);
    }

    /**
     * @param  array<int, array<string, mixed>>  $declared
     * @param  array<int, string>  $validReferences
     */
    private function recordRejected(ConversationReplySuggestion $suggestion, array $declared, array $validReferences): void
    {
        $seen = [];

        foreach ($declared as $citation) {
            if (! is_array($citation)) {
                continue;
            }

            $reference = trim((string) ($citation['chunk_id'] ?? ''));
            $documentId = isset($citation['document_id']) ? (int) $citation['document_id'] : 0;
            $label = $reference !== '' ? $reference : 'documento '.$documentId;

            if (in_array($reference, $validReferences, true) || in_array($label, $seen, true)) {
                continue;
            }

            $seen[] = $label;

            ReplySuggestionCitation::create([
                'conversation_reply_suggestion_id' => $suggestion->id,
                'chunk_reference' => mb_substr($label, 0, 255),
                'is_valid' => false,
                'invalid_reason' => 'fora do conjunto recuperado',
            ]);
        }
    }

    /**
     * Referencia do trecho para o id da linha de log correspondente.
     *
     * @return array<string, int>
     */
    private function retrievalChunkIds(?KnowledgeRetrieval $retrieval): array
    {
        if ($retrieval === null) {
            return [];
        }

        return KnowledgeRetrievalChunk::query()
            ->where('knowledge_retrieval_id', $retrieval->id)
            ->pluck('id', 'chunk_reference')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
