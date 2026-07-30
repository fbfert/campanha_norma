<?php

namespace App\Services\Knowledge;

use App\Enums\RetrievalStrategy;
use App\Models\ConversationFlow;
use App\Services\SystemSettingService;

/**
 * Porta de entrada da camada de conhecimento.
 *
 * Concentra a unica condicao que liga a recuperacao: a chave global esta ligada e
 * existe base ativa associada ao fluxo. Deixar essa decisao em um lugar so evita
 * que ela seja reimplementada com criterio diferente em cada chamador.
 */
class KnowledgeGuard
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('knowledge.enabled', '0');
    }

    /**
     * Bases que participam da recuperacao para este fluxo.
     *
     * @return array<int, int>
     */
    public function baseIdsForFlow(?ConversationFlow $flow): array
    {
        if (! $this->enabled() || $flow === null) {
            return [];
        }

        return $flow->retrievableKnowledgeBases()->pluck('knowledge_bases.id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Um fluxo sem base ativa associada nao produz recuperacao alguma. E o
     * comportamento correto: a base e opt-in por fluxo, nao global.
     */
    public function groundingEnabledForFlow(?ConversationFlow $flow): bool
    {
        return $this->baseIdsForFlow($flow) !== [];
    }

    public function strategy(): RetrievalStrategy
    {
        return RetrievalStrategy::tryFrom((string) $this->settings->get('knowledge.retrieval_strategy', 'lexical'))
            ?? RetrievalStrategy::Lexical;
    }

    public function topK(): int
    {
        return max(1, (int) $this->settings->get('knowledge.top_k', 5));
    }

    public function threshold(): float
    {
        return (float) $this->settings->get('knowledge.score_threshold', 0.25);
    }

    public function maxContextChars(): int
    {
        return max(200, (int) $this->settings->get('knowledge.max_context_chars', 4000));
    }

    public function maxVectorCandidates(): int
    {
        return max(1, (int) $this->settings->get('knowledge.max_vector_candidates', 5000));
    }

    public function showCitationsToContact(): bool
    {
        return (bool) $this->settings->get('knowledge.show_citations_to_contact', '0');
    }

    public function retrievalRetentionDays(): int
    {
        return max(0, (int) $this->settings->get('knowledge.retrieval_retention_days', 180));
    }
}
