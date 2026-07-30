<?php

namespace App\Services\Analytics;

use App\Models\ConversationInsight;
use App\Services\SystemSettingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Problemas, ações sugeridas e resultados desejados, em agregado.
 *
 * Os exemplos devolvidos por `examples()` carregam apenas o texto do próprio
 * campo de demanda — nunca nome, telefone ou identificador de contato. Um
 * exemplo existe para dar concretude a um número, e o número não fica mais
 * concreto por vir com o nome de quem o gerou.
 */
class DemandMetricsService
{
    public function __construct(
        private readonly SmallGroupSuppressor $suppressor,
        private readonly SystemSettingService $settings,
    ) {}

    /**
     * Problemas mais identificados, agrupados pelo texto normalizado.
     *
     * @return array<int, array<string, mixed>>
     */
    public function problems(Carbon $from, Carbon $to, ?int $flowId = null, int $limit = 30): array
    {
        return $this->grouped('identified_problem', $from, $to, $flowId, $limit);
    }

    /** @return array<int, array<string, mixed>> */
    public function actions(Carbon $from, Carbon $to, ?int $flowId = null, int $limit = 30): array
    {
        return $this->grouped('suggested_action', $from, $to, $flowId, $limit);
    }

    /** @return array<int, array<string, mixed>> */
    public function results(Carbon $from, Carbon $to, ?int $flowId = null, int $limit = 30): array
    {
        return $this->grouped('desired_result', $from, $to, $flowId, $limit);
    }

    /**
     * Distribuição por urgência.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byUrgency(Carbon $from, Carbon $to, ?int $flowId = null): array
    {
        $rows = $this->base($from, $to, $flowId)
            ->whereNotNull('urgency')
            ->select('urgency', DB::raw('count(*) as total'))
            ->groupBy('urgency')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => ['urgency' => $this->text($row->urgency), 'total' => (int) $row->total]);

        return $this->suppressor->rows($rows);
    }

    /**
     * Exemplos anonimizados de demanda.
     *
     * @return array<int, array<string, mixed>>
     */
    public function examples(Carbon $from, Carbon $to, ?int $flowId = null, int $limit = 10): array
    {
        return $this->base($from, $to, $flowId)
            ->whereNotNull('identified_problem')
            ->where('identified_problem', '!=', '')
            ->where('reviewed', true)
            ->orderByDesc('confidence')
            ->limit($limit)
            ->get(['identified_problem', 'suggested_action', 'urgency', 'region'])
            ->map(fn ($insight): array => [
                'problem' => (string) $insight->identified_problem,
                'action' => $insight->suggested_action === null ? null : (string) $insight->suggested_action,
                'urgency' => $this->text($insight->urgency),
                'region' => $insight->region === null ? null : (string) $insight->region,
            ])
            ->all();
    }

    /**
     * Fila de itens de baixa confiança, que não devem entrar nos totais como
     * resultado assentado enquanto ninguém conferiu.
     *
     * @return array<string, int>
     */
    public function reviewQueue(Carbon $from, Carbon $to, ?int $flowId = null): array
    {
        $threshold = (float) $this->settings->get('analytics.low_confidence_threshold', 0.70);

        return [
            'low_confidence' => (int) $this->base($from, $to, $flowId)->where('confidence', '<', $threshold)->where('reviewed', false)->count(),
            'flagged' => (int) $this->base($from, $to, $flowId)->where('requires_human_review', true)->where('reviewed', false)->count(),
            'threshold_used' => (int) round($threshold * 100),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function grouped(string $column, Carbon $from, Carbon $to, ?int $flowId, int $limit): array
    {
        $rows = $this->base($from, $to, $flowId)
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->select($column.' as label', DB::raw('count(*) as total'))
            ->groupBy($column)
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => ['label' => (string) $row->label, 'total' => (int) $row->total]);

        return $this->suppressor->rows($rows);
    }

    /**
     * O modelo converte alguns campos em enum e outros não, conforme a coluna.
     * Uma única função de leitura evita que a tela quebre por causa dessa
     * diferença, que não interessa a quem le o relatório.
     */
    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }

    private function base(Carbon $from, Carbon $to, ?int $flowId = null)
    {
        return ConversationInsight::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($flowId, fn ($query) => $query->where('conversation_flow_id', $flowId));
    }
}
