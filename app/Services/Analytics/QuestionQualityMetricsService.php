<?php

namespace App\Services\Analytics;

use App\Enums\ConversationFlowStage;
use App\Models\ConversationFlowQuestion;
use App\Models\ConversationFlowState;
use App\Models\ConversationInsight;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Desempenho de cada pergunta do fluxo.
 *
 * O objetivo declarado e clareza e capacidade de coletar opinião. Não existe
 * aqui nenhuma medida de efeito persuasivo, apoio declarado ou intenção de
 * voto, e não ha ordenação por nada disso — otimizar pergunta para persuadir e
 * exatamente o uso que a etapa proibe.
 *
 * O que a tela permite concluir e: esta pergunta e entendida? Ela rende
 * resposta útil? Ela manda gente para atendimento humano com frequência
 * anormal? As três levam a reescrever a pergunta, não a pessoa.
 */
class QuestionQualityMetricsService
{
    public function __construct(private readonly SmallGroupSuppressor $suppressor) {}

    /**
     * Uma linha por pergunta utilizada no período.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byQuestion(Carbon $from, Carbon $to, ?int $flowId = null): array
    {
        $asked = ConversationFlowState::query()
            ->whereBetween('started_at', [$from, $to])
            ->whereNotNull('selected_question_id')
            ->when($flowId, fn ($query) => $query->where('conversation_flow_id', $flowId))
            ->select(
                'selected_question_id',
                DB::raw('count(*) as asked'),
                DB::raw("sum(case when current_stage in ('answer_received','completed') then 1 else 0 end) as answered"),
                DB::raw("sum(case when current_stage = 'completed' then 1 else 0 end) as completed"),
                DB::raw("sum(case when current_stage = '".ConversationFlowStage::WaitingHuman->value."' then 1 else 0 end) as handoff"),
            )
            ->groupBy('selected_question_id')
            ->get()
            ->keyBy('selected_question_id');

        if ($asked->isEmpty()) {
            return [];
        }

        $lengths = ConversationInsight::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('conversation_flow_question_id', $asked->keys())
            ->select('conversation_flow_question_id', DB::raw('avg(char_length(summary)) as average_length'))
            ->groupBy('conversation_flow_question_id')
            ->pluck('average_length', 'conversation_flow_question_id');

        $questions = ConversationFlowQuestion::query()
            ->withTrashed()
            ->whereIn('id', $asked->keys())
            ->get(['id', 'internal_title', 'text', 'is_active'])
            ->keyBy('id');

        $rows = $asked->map(function ($row) use ($questions, $lengths): array {
            $question = $questions->get($row->selected_question_id);
            $askedTotal = (int) $row->asked;

            return [
                'question_id' => (int) $row->selected_question_id,
                'title' => $question?->internal_title ?? 'Pergunta removida',
                'text' => $question?->text,
                'is_active' => (bool) ($question?->is_active ?? false),
                'total' => $askedTotal,
                'answered' => (int) $row->answered,
                'completed' => (int) $row->completed,
                'handoff' => (int) $row->handoff,
                'response_rate' => $this->rate((int) $row->answered, $askedTotal),
                'completion_rate' => $this->rate((int) $row->completed, $askedTotal),
                'handoff_rate' => $this->rate((int) $row->handoff, $askedTotal),
                'average_answer_length' => isset($lengths[$row->selected_question_id])
                    ? (int) round((float) $lengths[$row->selected_question_id])
                    : null,
            ];
        })->values();

        return $this->suppressor->rows($rows);
    }

    /**
     * Taxa de permissão da mensagem de apresentação, por fluxo.
     *
     * A mensagem inicial e a única coisa que a pessoa leu antes de decidir se
     * autoriza. Uma taxa baixa aqui e problema de texto de apresentação, não
     * das perguntas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function permissionByFlow(Carbon $from, Carbon $to): array
    {
        return ConversationFlowState::query()
            ->join('conversation_flows', 'conversation_flows.id', '=', 'conversation_flow_states.conversation_flow_id')
            ->whereBetween('conversation_flow_states.started_at', [$from, $to])
            ->select(
                'conversation_flows.id',
                'conversation_flows.name',
                DB::raw("sum(case when conversation_flow_states.current_stage in ('permission_granted','question_selected','waiting_answer','answer_received','completed') then 1 else 0 end) as granted"),
                DB::raw("sum(case when conversation_flow_states.current_stage = 'permission_denied' then 1 else 0 end) as denied"),
                DB::raw("sum(case when conversation_flow_states.current_stage = 'opted_out' then 1 else 0 end) as opted_out"),
            )
            ->groupBy('conversation_flows.id', 'conversation_flows.name')
            ->get()
            ->map(function ($row): array {
                $granted = (int) $row->granted;
                $answered = $granted + (int) $row->denied + (int) $row->opted_out;

                return [
                    'flow_id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'granted' => $granted,
                    'denied' => (int) $row->denied,
                    'opted_out' => (int) $row->opted_out,
                    'permission_rate' => $this->rate($granted, $answered),
                ];
            })
            ->all();
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
