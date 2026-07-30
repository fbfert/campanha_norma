<?php

namespace App\Services\Analytics;

use App\Models\ConversationInsight;
use App\Services\SystemSettingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Métricas de tema extraidas pela interpretação.
 *
 * Tudo aqui e contagem e média. Nenhum método devolve texto de mensagem,
 * telefone ou identificador de contato: o detalhamento que mostra conteúdo vive
 * na tela de insights da 9B, que já tem permissão própria para isso.
 */
class TopicMetricsService
{
    public function __construct(
        private readonly SmallGroupSuppressor $suppressor,
        private readonly SystemSettingService $settings,
    ) {}

    /**
     * Temas mais mencionados, com confiança média e quantidade revisada.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mostMentioned(Carbon $from, Carbon $to, ?int $flowId = null, int $limit = 20): array
    {
        $rows = $this->base($from, $to, $flowId)
            ->join('insight_topics', 'insight_topics.id', '=', 'conversation_insights.insight_topic_id')
            ->select(
                'insight_topics.id',
                'insight_topics.name',
                'insight_topics.parent_id',
                DB::raw('count(*) as total'),
                DB::raw('avg(conversation_insights.confidence) as average_confidence'),
                DB::raw('sum(case when conversation_insights.reviewed = 1 then 1 else 0 end) as reviewed'),
            )
            ->groupBy('insight_topics.id', 'insight_topics.name', 'insight_topics.parent_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'topic_id' => (int) $row->id,
                'name' => (string) $row->name,
                'parent_id' => $row->parent_id === null ? null : (int) $row->parent_id,
                'total' => (int) $row->total,
                'average_confidence' => $row->average_confidence === null ? null : round((float) $row->average_confidence, 3),
                'reviewed' => (int) $row->reviewed,
            ]);

        return $this->suppressor->rows($rows);
    }

    /**
     * Insights sem tema atribuído.
     *
     * Contagem separada e deliberada: somar não classificados dentro de "outros"
     * esconderia falha de classificação dentro de um tema legítimo.
     */
    public function unclassified(Carbon $from, Carbon $to, ?int $flowId = null): int
    {
        return (int) $this->base($from, $to, $flowId)->whereNull('insight_topic_id')->count();
    }

    /**
     * Temas que aparecem no período atual e não apareciam no anterior.
     *
     * Exige um mínimo de menções configurável: uma única menção nova não e
     * tendência, e tratar como tal encheria a tela de ruído a cada período.
     *
     * @return array<int, array<string, mixed>>
     */
    public function emerging(Carbon $from, Carbon $to, Carbon $previousFrom, Carbon $previousTo, ?int $flowId = null): array
    {
        $minimum = max(1, (int) $this->settings->get('analytics.emerging_topic_min_mentions', 3));

        $previous = $this->base($previousFrom, $previousTo, $flowId)
            ->whereNotNull('insight_topic_id')
            ->distinct()
            ->pluck('insight_topic_id')
            ->all();

        $current = $this->mostMentioned($from, $to, $flowId, 100);

        return array_values(array_filter(
            $current,
            fn (array $row): bool => ! in_array($row['topic_id'], $previous, true)
                && $row['total'] !== null
                && $row['total'] >= $minimum
        ));
    }

    /**
     * Tendência por dia dos temas mais citados.
     *
     * @return array<int, array<string, mixed>>
     */
    public function trend(Carbon $from, Carbon $to, ?int $flowId = null, int $topics = 5): array
    {
        $ids = collect($this->mostMentioned($from, $to, $flowId, $topics))
            ->pluck('topic_id')
            ->all();

        if ($ids === []) {
            return [];
        }

        return $this->base($from, $to, $flowId)
            ->join('insight_topics', 'insight_topics.id', '=', 'conversation_insights.insight_topic_id')
            ->whereIn('insight_topics.id', $ids)
            ->selectRaw('date(conversation_insights.created_at) as day, insight_topics.name as name, count(*) as total')
            ->groupBy('day', 'insight_topics.name')
            ->orderBy('day')
            ->get()
            ->map(fn ($row): array => [
                'day' => (string) $row->day,
                'name' => (string) $row->name,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * Resumo de confiança e revisão humana do período.
     *
     * @return array<string, mixed>
     */
    public function quality(Carbon $from, Carbon $to, ?int $flowId = null): array
    {
        $threshold = (float) $this->settings->get('analytics.low_confidence_threshold', 0.70);
        $base = $this->base($from, $to, $flowId);

        $total = (int) (clone $base)->count();
        $reviewed = (int) (clone $base)->where('reviewed', true)->count();
        $lowConfidence = (int) (clone $base)->where('confidence', '<', $threshold)->count();
        $needsReview = (int) (clone $base)->where('requires_human_review', true)->count();

        return [
            'total' => $total,
            'reviewed' => $reviewed,
            'low_confidence' => $lowConfidence,
            'needs_review' => $needsReview,
            'threshold' => $threshold,
            'average_confidence' => $total > 0
                ? round((float) (clone $base)->avg('confidence'), 3)
                : null,
        ];
    }

    private function base(Carbon $from, Carbon $to, ?int $flowId = null)
    {
        return ConversationInsight::query()
            ->whereBetween('conversation_insights.created_at', [$from, $to])
            ->when($flowId, fn ($query) => $query->where('conversation_insights.conversation_flow_id', $flowId));
    }
}
