<?php

namespace App\Services\Analytics;

use App\Enums\ReplySuggestionStatus;
use App\Enums\AiRunPurpose;
use App\Models\AiRun;
use App\Models\ConversationInsight;
use App\Models\ConversationReplySuggestion;
use App\Services\SystemSettingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Qualidade operacional da interpretação e da geração.
 *
 * O relatório compara versões de prompt e modelos lado a lado e para por ai.
 * Nenhum método aqui promove versão, altera configuração ou sugere troca: a
 * decisão de mudar o que responde a cidadão e humana, e um sistema que se
 * reconfigura sozinho com base na própria métrica perde o único ponto em que
 * alguém responde pelo resultado.
 */
class AiQualityMetricsService
{
    public function __construct(private readonly SystemSettingService $settings) {}

    /**
     * Desfecho das sugestões geradas no período.
     *
     * Aprovada sem edição: o texto final e igual ao gerado, ou não houve texto
     * final. Aprovada com edição: houve texto final diferente do gerado. A
     * distinção importa porque edição constante e o sinal mais barato de que o
     * prompt esta errado.
     *
     * @return array<string, mixed>
     */
    public function suggestions(Carbon $from, Carbon $to, ?int $flowId = null): array
    {
        $base = ConversationReplySuggestion::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($flowId, fn ($query) => $query->where('conversation_flow_id', $flowId));

        $decided = (clone $base)->whereIn('status', [
            ReplySuggestionStatus::Approved->value,
            ReplySuggestionStatus::Sent->value,
            ReplySuggestionStatus::Rejected->value,
        ]);

        $approved = (clone $base)->whereIn('status', [
            ReplySuggestionStatus::Approved->value,
            ReplySuggestionStatus::Sent->value,
        ]);

        $withoutEdit = (clone $approved)->where(function ($query): void {
            $query->whereNull('final_text')->orWhereColumn('final_text', '=', 'generated_text');
        })->count();

        $totalApproved = (int) $approved->count();
        $totalDecided = (int) $decided->count();

        return [
            'total' => (int) (clone $base)->count(),
            'pending' => (int) (clone $base)->where('status', ReplySuggestionStatus::Pending->value)->count(),
            'approved' => $totalApproved,
            'approved_without_edit' => $withoutEdit,
            'approved_with_edit' => $totalApproved - $withoutEdit,
            'rejected' => (int) (clone $base)->where('status', ReplySuggestionStatus::Rejected->value)->count(),
            'blocked' => (int) (clone $base)->where('status', ReplySuggestionStatus::Blocked->value)->count(),
            'expired' => (int) (clone $base)->where('status', ReplySuggestionStatus::Expired->value)->count(),
            'failed' => (int) (clone $base)->where('status', ReplySuggestionStatus::Failed->value)->count(),
            'requires_review' => (int) (clone $base)->where('requires_human_review', true)->count(),
            'rates' => [
                'approved_without_edit' => $this->rate($withoutEdit, $totalApproved),
                'rejection' => $this->rate((int) (clone $base)->where('status', ReplySuggestionStatus::Rejected->value)->count(), $totalDecided),
                'handoff' => $this->rate((int) (clone $base)->where('requires_human_review', true)->count(), (int) (clone $base)->count()),
            ],
        ];
    }

    /**
     * Motivos de recusa e de encaminhamento, agrupados.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function reasons(Carbon $from, Carbon $to, ?int $flowId = null): array
    {
        $base = fn () => ConversationReplySuggestion::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($flowId, fn ($query) => $query->where('conversation_flow_id', $flowId));

        $group = fn ($query, string $column): array => $query
            ->whereNotNull($column)
            ->select($column.' as label', DB::raw('count(*) as total'))
            ->groupBy($column)
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row): array => ['label' => (string) $row->label, 'total' => (int) $row->total])
            ->all();

        return [
            'handoff' => $group($base(), 'handoff_reason'),
            'blocked' => $group($base(), 'blocked_reason'),
            'grounding' => $group($base(), 'grounding_status'),
            'feedback' => $group($base(), 'feedback'),
        ];
    }

    /**
     * Execuções agrupadas por provedor, modelo e versão de prompt.
     *
     * O custo so e incluído quando quem pede tem permissão para ve-lo. A
     * qualidade continua legível sem ele: quem acompanha acerto do modelo não
     * precisa saber quanto a operação custa.
     *
     * @return array<int, array<string, mixed>>
     */
    public function runs(Carbon $from, Carbon $to, bool $includeCost = false): array
    {
        return AiRun::query()
            ->whereBetween('created_at', [$from, $to])
            ->select(
                'purpose', 'provider', 'model', 'prompt_version',
                DB::raw('count(*) as total'),
                DB::raw("sum(case when status = 'failed' then 1 else 0 end) as failures"),
                DB::raw('avg(latency_ms) as average_latency'),
                DB::raw('sum(total_tokens) as tokens'),
                DB::raw('sum(estimated_cost) as cost'),
            )
            ->groupBy('purpose', 'provider', 'model', 'prompt_version')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row) use ($includeCost): array {
                $line = [
                    // `purpose` tem cast de enum no modelo, e converter enum
                    // para texto direto e erro fatal em PHP. A tela inteira
                    // caia em 500 assim que existisse uma execução de IA
                    // registrada — o painel só abria enquanto estava vazio.
                    'purpose' => $row->purpose instanceof AiRunPurpose ? $row->purpose->value : (string) $row->purpose,
                    'provider' => (string) $row->provider,
                    'model' => (string) $row->model,
                    'prompt_version' => (string) $row->prompt_version,
                    'total' => (int) $row->total,
                    'failures' => (int) $row->failures,
                    'failure_rate' => $this->rate((int) $row->failures, (int) $row->total),
                    'average_latency' => $row->average_latency === null ? null : (int) round((float) $row->average_latency),
                ];

                if ($includeCost) {
                    $line['tokens'] = (int) $row->tokens;
                    $line['cost'] = $row->cost === null ? null : round((float) $row->cost, 6);
                }

                return $line;
            })
            ->all();
    }

    /**
     * Correção humana de classificação.
     *
     * Numerador: insights revisados cuja revisão registrou um motivo, ou seja,
     * alguém olhou e mudou algo. Denominador: insights revisados. Insights que
     * ninguém olhou ficam de fora dos dois lados — não foram corrigidos nem
     * confirmados.
     *
     * @return array<string, mixed>
     */
    public function classificationCorrections(Carbon $from, Carbon $to, ?int $flowId = null): array
    {
        $base = ConversationInsight::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($flowId, fn ($query) => $query->where('conversation_flow_id', $flowId));

        $reviewed = (int) (clone $base)->where('reviewed', true)->count();
        $corrected = (int) (clone $base)->where('reviewed', true)->whereNotNull('review_reason')->count();

        return [
            'reviewed' => $reviewed,
            'corrected' => $corrected,
            'rate' => $this->rate($corrected, $reviewed),
        ];
    }

    /**
     * @return array{value: float|null, numerator: int, denominator: int}
     */
    private function rate(int $numerator, int $denominator): array
    {
        return [
            'value' => $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : null,
            'numerator' => $numerator,
            'denominator' => $denominator,
        ];
    }
}
