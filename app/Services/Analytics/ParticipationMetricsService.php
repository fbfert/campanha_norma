<?php

namespace App\Services\Analytics;

use App\Enums\ConversationFlowStage;
use App\Enums\ConversationMessageDirection;
use App\Models\ConversationFlowState;
use App\Models\ConversationMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Metricas de participacao da pesquisa conversacional.
 *
 * Cada taxa declara numerador, denominador e exclusoes. Isso nao e formalidade:
 * "taxa de resposta de 100%" sobre duas conversas e um numero verdadeiro e
 * inutil, e a diferenca so aparece quando o denominador esta a vista. Por isso
 * toda taxa devolvida carrega junto os dois numeros que a formaram.
 *
 * Os estagios sao lidos do estado atual da conversa. Um estagio terminal
 * (`completed`, `opted_out`, `permission_denied`, `waiting_human`, `failed`)
 * nao volta atras, entao contar por estado atual e equivalente a contar por
 * evento — e nao exige um historico de transicoes que a 9A nao guarda para
 * esse fim.
 */
class ParticipationMetricsService
{
    /**
     * Estagios que so sao alcancados depois de a pessoa autorizar.
     *
     * @var list<string>
     */
    private const GRANTED_STAGES = [
        'permission_granted',
        'question_selected',
        'waiting_answer',
        'answer_received',
        'completed',
    ];

    /** @var list<string> */
    private const ANSWERED_STAGES = ['answer_received', 'completed'];

    /**
     * Contagens brutas do periodo. E a fonte tanto da tela quanto da
     * materializacao diaria, para que os dois nunca discordem.
     *
     * @return array<string, int>
     */
    public function totals(Carbon $from, Carbon $to, ?int $flowId = null): array
    {
        $base = ConversationFlowState::query()
            ->whereBetween('started_at', [$from, $to])
            ->when($flowId, fn ($query) => $query->where('conversation_flow_id', $flowId));

        $byStage = (clone $base)
            ->select('current_stage', DB::raw('count(*) as total'))
            ->groupBy('current_stage')
            ->pluck('total', 'current_stage');

        $stage = fn (ConversationFlowStage $case): int => (int) ($byStage[$case->value] ?? 0);
        $sum = fn (array $stages): int => array_sum(array_map(fn (string $s): int => (int) ($byStage[$s] ?? 0), $stages));

        $turns = (clone $base)->where('automated_messages_count', '>', 0);

        return [
            'approached' => (int) $byStage->sum(),
            'permission_granted' => $sum(self::GRANTED_STAGES),
            'permission_denied' => $stage(ConversationFlowStage::PermissionDenied),
            'opted_out' => $stage(ConversationFlowStage::OptedOut),
            'answers_received' => $sum(self::ANSWERED_STAGES),
            'completed' => $stage(ConversationFlowStage::Completed),
            'waiting_human' => $stage(ConversationFlowStage::WaitingHuman),
            'failed' => $stage(ConversationFlowStage::Failed),
            'paused' => $stage(ConversationFlowStage::Paused),
            'automated_messages' => (int) (clone $base)->sum('automated_messages_count'),
            'conversations_with_turns' => (int) $turns->count(),
        ];
    }

    /**
     * Tempo ate a primeira resposta, em segundos.
     *
     * Medido do inicio do fluxo ate a primeira mensagem recebida daquela
     * conversa depois do inicio. Conversas sem nenhuma resposta ficam de fora
     * do denominador: incluir silencio como tempo infinito, ou como zero,
     * distorceria a media nos dois sentidos.
     *
     * @return array{total: int, samples: int, average: float|null}
     */
    public function firstReply(Carbon $from, Carbon $to, ?int $flowId = null): array
    {
        $states = ConversationFlowState::query()
            ->whereBetween('started_at', [$from, $to])
            ->whereNotNull('started_at')
            ->when($flowId, fn ($query) => $query->where('conversation_flow_id', $flowId))
            ->get(['conversation_id', 'started_at']);

        $total = 0;
        $samples = 0;

        foreach ($states as $state) {
            $first = ConversationMessage::query()
                ->where('conversation_id', $state->conversation_id)
                ->where('direction', ConversationMessageDirection::Incoming->value)
                ->where('created_at', '>=', $state->started_at)
                ->orderBy('created_at')
                ->value('created_at');

            if ($first === null) {
                continue;
            }

            $seconds = Carbon::parse($first)->diffInSeconds($state->started_at, absolute: true);

            $total += (int) $seconds;
            $samples++;
        }

        return [
            'total' => $total,
            'samples' => $samples,
            'average' => $samples > 0 ? round($total / $samples, 1) : null,
        ];
    }

    /**
     * Painel completo, com cada taxa acompanhada do par que a formou.
     *
     * @return array<string, mixed>
     */
    public function overview(Carbon $from, Carbon $to, ?int $flowId = null): array
    {
        $totals = $this->totals($from, $to, $flowId);
        $firstReply = $this->firstReply($from, $to, $flowId);

        // Denominador da taxa de permissao: apenas quem respondeu ao pedido.
        // Quem ainda nao respondeu nao e uma negativa, e contar silencio como
        // recusa produziria uma taxa que so cai com o tempo.
        $answeredPermission = $totals['permission_granted'] + $totals['permission_denied'] + $totals['opted_out'];

        return [
            'totals' => $totals,
            'first_reply_seconds' => $firstReply,
            'rates' => [
                'permission' => $this->rate($totals['permission_granted'], $answeredPermission),
                'response' => $this->rate($totals['answers_received'], $totals['permission_granted']),
                'completion' => $this->rate($totals['completed'], $totals['approached']),
                'opt_out' => $this->rate($totals['opted_out'], $totals['approached']),
                'handoff' => $this->rate($totals['waiting_human'], $totals['approached']),
            ],
            'average_turns' => $totals['conversations_with_turns'] > 0
                ? round($totals['automated_messages'] / $totals['conversations_with_turns'], 2)
                : null,
        ];
    }

    /**
     * Comparacao entre dois periodos.
     *
     * Devolve os dois lados e a diferenca. Nao calcula variacao percentual
     * quando o periodo anterior e zero, e nao afirma causa: dois numeros lado a
     * lado sao um fato, e a explicacao para a diferenca nao esta nos dados.
     *
     * @return array<string, mixed>
     */
    public function compare(Carbon $from, Carbon $to, Carbon $previousFrom, Carbon $previousTo, ?int $flowId = null): array
    {
        $current = $this->totals($from, $to, $flowId);
        $previous = $this->totals($previousFrom, $previousTo, $flowId);
        $difference = [];

        foreach ($current as $key => $value) {
            $difference[$key] = $value - ($previous[$key] ?? 0);
        }

        return ['current' => $current, 'previous' => $previous, 'difference' => $difference];
    }

    /**
     * Serie diaria para o grafico de participacao.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byDay(Carbon $from, Carbon $to, ?int $flowId = null): array
    {
        return ConversationFlowState::query()
            ->selectRaw('date(started_at) as day, current_stage, count(*) as total')
            ->whereBetween('started_at', [$from, $to])
            ->when($flowId, fn ($query) => $query->where('conversation_flow_id', $flowId))
            ->groupBy('day', 'current_stage')
            ->orderBy('day')
            ->get()
            ->map(fn ($row): array => [
                'day' => (string) $row->day,
                'stage' => (string) $row->current_stage,
                'total' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * Taxa com denominador explicito. Sem denominador nao ha taxa: devolve
     * nulo, e a tela mostra um traco em vez de zero por cento.
     *
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
