<?php

namespace App\Services\Analytics;

use App\Enums\ConversationFlowStage;
use App\Enums\KnowledgeDocumentStatus;
use App\Models\AiRun;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Models\ConversationReplySuggestion;
use App\Models\KnowledgeDocument;
use App\Services\SystemSettingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Retrato único do que esta ligado, do que esta em uso e do que esta pendente.
 *
 * A parte mais útil não são os totais, e `divergences()`. Um sistema com quatro
 * interruptores independentes tem estados intermediários que parecem
 * funcionando e não estão: geração ligada sem provedor, base ligada sem
 * documento aprovado, automação ligada sem fluxo ativo. Cada um desses produz
 * silêncio, não erro — ninguém reclama, e nada acontece.
 */
class GovernanceReportService
{
    public function __construct(private readonly SystemSettingService $settings) {}

    /** @return array<string, mixed> */
    public function report(Carbon $from, Carbon $to): array
    {
        return [
            'switches' => $this->switches(),
            'flows' => $this->flows(),
            'documents' => $this->documents(),
            'versions' => $this->versions(),
            'thresholds' => $this->thresholds(),
            'sensitive' => $this->sensitiveEvents($from, $to),
            'pending' => $this->pending(),
            'failures' => $this->failures($from, $to),
            'divergences' => $this->divergences(),
            'changes' => $this->recentChanges(),
        ];
    }

    /** @return array<string, bool> */
    public function switches(): array
    {
        return [
            'automation' => $this->flag('conversation_automation.enabled'),
            'auto_send' => $this->flag('conversation_automation.auto_send_enabled'),
            'interpretation' => $this->flag('ai.enabled'),
            'generation' => $this->settings->get('ai.response.mode', 'disabled') !== 'disabled',
            'knowledge' => $this->flag('knowledge.enabled'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function flows(): array
    {
        return ConversationFlow::query()
            ->withCount(['questions as active_questions' => fn ($query) => $query->where('is_active', true)])
            ->get(['id', 'name', 'status', 'max_main_questions', 'max_followups', 'transparency_enabled'])
            ->map(fn ($flow): array => [
                'id' => (int) $flow->id,
                'name' => (string) $flow->name,
                'status' => (string) ($flow->status->value ?? $flow->status),
                'active_questions' => (int) $flow->active_questions,
                'max_followups' => (int) $flow->max_followups,
                'transparency_enabled' => (bool) $flow->transparency_enabled,
            ])
            ->all();
    }

    /** @return array<string, int> */
    public function documents(): array
    {
        $byStatus = KnowledgeDocument::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'approved' => (int) ($byStatus[KnowledgeDocumentStatus::Approved->value] ?? 0),
            'obsolete' => (int) ($byStatus[KnowledgeDocumentStatus::Obsolete->value] ?? 0),
            'failed' => (int) ($byStatus[KnowledgeDocumentStatus::Failed->value] ?? 0),
            'total' => (int) $byStatus->sum(),
        ];
    }

    /** @return array<string, string> */
    public function versions(): array
    {
        return [
            'ai_provider' => (string) config('ai.provider'),
            'ai_model' => (string) config('ai.providers.openai.model'),
            'classification_prompt' => (string) $this->settings->get('ai.classification_prompt_version', '-'),
            'extraction_prompt' => (string) $this->settings->get('ai.extraction_prompt_version', '-'),
            'response_prompt' => (string) $this->settings->get('ai.response.prompt_version', '-'),
            'retrieval_strategy' => (string) $this->settings->get('knowledge.retrieval_strategy', '-'),
        ];
    }

    /** @return array<string, string> */
    public function thresholds(): array
    {
        return [
            'classificação mínima' => (string) $this->settings->get('ai.min_classification_confidence', '-'),
            'extração mínima' => (string) $this->settings->get('ai.min_extraction_confidence', '-'),
            'resposta mínima' => (string) $this->settings->get('ai.response.min_confidence', '-'),
            'busca mínima' => (string) $this->settings->get('knowledge.score_threshold', '-'),
            'célula mínima em relatório' => (string) $this->settings->get('analytics.minimum_cell_size', '5'),
            'máximo de turnos automáticos' => (string) $this->settings->get('conversation_automation.max_automated_messages', '-'),
        ];
    }

    /** @return array<string, int> */
    public function sensitiveEvents(Carbon $from, Carbon $to): array
    {
        return [
            'opt_outs' => (int) ConversationFlowState::query()
                ->whereBetween('started_at', [$from, $to])
                ->where('current_stage', ConversationFlowStage::OptedOut->value)
                ->count(),
            'do_not_contact' => (int) Contact::query()->where('do_not_contact', true)->count(),
            'handoffs' => (int) ConversationFlowState::query()
                ->whereBetween('started_at', [$from, $to])
                ->where('current_stage', ConversationFlowStage::WaitingHuman->value)
                ->count(),
            'blocked_suggestions' => (int) ConversationReplySuggestion::query()
                ->whereBetween('created_at', [$from, $to])
                ->where('status', 'blocked')
                ->count(),
        ];
    }

    /** @return array<string, int> */
    public function pending(): array
    {
        return [
            'suggestions_pending' => (int) ConversationReplySuggestion::query()->where('status', 'pending')->count(),
            'insights_unreviewed' => (int) DB::table('conversation_insights')->where('requires_human_review', true)->where('reviewed', false)->count(),
            'documents_processing' => (int) KnowledgeDocument::query()->where('status', KnowledgeDocumentStatus::Processing->value)->count(),
            'states_waiting_human' => (int) ConversationFlowState::query()->where('current_stage', ConversationFlowStage::WaitingHuman->value)->count(),
        ];
    }

    /** @return array<string, int> */
    public function failures(Carbon $from, Carbon $to): array
    {
        return [
            'ai_runs_failed' => (int) AiRun::query()->whereBetween('created_at', [$from, $to])->where('status', 'failed')->count(),
            'jobs_failed' => (int) DB::table('failed_jobs')->count(),
            'documents_failed' => (int) KnowledgeDocument::query()->where('status', KnowledgeDocumentStatus::Failed->value)->count(),
        ];
    }

    /**
     * Combinações que parecem ligadas e não produzem efeito.
     *
     * @return array<int, string>
     */
    public function divergences(): array
    {
        $switches = $this->switches();
        $divergences = [];

        if ($switches['interpretation'] && (string) config('ai.provider') === 'null') {
            $divergences[] = 'A interpretação esta ligada e nenhum provedor de IA esta configurado. Nenhuma mensagem será interpretada.';
        }

        if ($switches['generation'] && ! $switches['interpretation']) {
            $divergences[] = 'A geração de respostas esta ligada e a interpretação esta desligada. A geração depende da classificação da mensagem.';
        }

        if ($switches['knowledge'] && $this->documents()['approved'] === 0) {
            $divergences[] = 'A base de conhecimento esta ligada e não existe documento aprovado. Nenhuma resposta será fundamentada.';
        }

        if ($switches['automation'] && $this->activeFlowCount() === 0) {
            $divergences[] = 'A automação esta ligada e não existe fluxo ativo. Nenhuma conversa avancara.';
        }

        if ($switches['auto_send'] && ! $switches['automation']) {
            $divergences[] = 'O envio automático esta ligado e a automação esta desligada. O envio não acontece.';
        }

        if ((string) $this->settings->get('knowledge.retrieval_strategy', 'lexical') !== 'lexical'
            && (string) config('knowledge.embeddings.provider') === 'null') {
            $divergences[] = 'A estratégia de busca exige embeddings e nenhum provedor de embeddings esta configurado. A busca cai para léxica.';
        }

        return $divergences;
    }

    /**
     * Últimas alterações de configuração registradas na auditoria.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentChanges(int $limit = 20): array
    {
        return AuditLog::query()
            ->whereIn('action', [
                'settings.updated', 'ai_provider.updated', 'conversation_flow.updated',
                'knowledge_base.status_changed', 'knowledge_document.approved',
            ])
            ->latest('id')
            ->limit($limit)
            ->get(['action', 'description', 'user_id', 'created_at'])
            ->map(fn ($entry): array => [
                'action' => (string) $entry->action,
                'description' => (string) $entry->description,
                'user_id' => $entry->user_id === null ? null : (int) $entry->user_id,
                'created_at' => $entry->created_at,
            ])
            ->all();
    }

    private function activeFlowCount(): int
    {
        return (int) ConversationFlow::query()->where('status', 'active')->count();
    }

    private function flag(string $key): bool
    {
        return (string) $this->settings->get($key, '0') === '1';
    }
}
