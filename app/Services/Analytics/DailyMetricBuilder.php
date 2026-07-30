<?php

namespace App\Services\Analytics;

use App\Models\ConversationDailyMetric;
use App\Models\ConversationFlow;
use Illuminate\Support\Carbon;

/**
 * Materializacao das metricas diarias de participacao.
 *
 * A reconstrucao e idempotente por construcao: a chave natural e (dia, fluxo) e
 * a escrita e `updateOrCreate`. Rodar o mesmo dia duas vezes produz o mesmo
 * estado, e reconstruir um dia antigo depois de uma correcao humana substitui
 * os valores em vez de somar a eles.
 *
 * O fluxo nulo representa o total do dia. A coluna auxiliar `flow_key` existe
 * porque MySQL nao considera dois nulos iguais em indice unico: sem ela, a
 * linha de total viraria uma linha nova a cada reconstrucao.
 */
class DailyMetricBuilder
{
    private const TOTAL_KEY = 0;

    public function __construct(private readonly ParticipationMetricsService $participation) {}

    /**
     * Reconstroi um intervalo de dias.
     *
     * @return int quantidade de linhas escritas
     */
    public function rebuild(Carbon $from, Carbon $to): int
    {
        $written = 0;

        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $written += $this->rebuildDay($day);
        }

        return $written;
    }

    /**
     * Reconstroi um unico dia: uma linha por fluxo mais a linha de total.
     */
    public function rebuildDay(Carbon $day): int
    {
        $start = $day->copy()->startOfDay();
        $end = $day->copy()->endOfDay();

        $flowIds = ConversationFlow::query()->pluck('id')->all();
        $written = 0;

        foreach ($flowIds as $flowId) {
            $this->write($start, $end, (int) $flowId);
            $written++;
        }

        $this->write($start, $end, null);

        return $written + 1;
    }

    private function write(Carbon $start, Carbon $end, ?int $flowId): void
    {
        $totals = $this->participation->totals($start, $end, $flowId);
        $firstReply = $this->participation->firstReply($start, $end, $flowId);
        $flowKey = $flowId ?? self::TOTAL_KEY;

        // A busca usa `whereDate` de proposito, em vez de `updateOrCreate` com
        // igualdade simples. Bancos diferentes guardam a coluna de data de
        // formas diferentes — um trunca para `2026-07-30`, outro mantem
        // `2026-07-30 00:00:00` — e uma comparacao literal acerta em um e erra
        // no outro. Errar aqui significa inserir linha nova a cada
        // reconstrucao, ou seja, perder exatamente a idempotencia que esta
        // classe existe para garantir.
        $existing = ConversationDailyMetric::query()
            ->whereDate('date', $start->toDateString())
            ->where('flow_key', $flowKey)
            ->first();

        $values = $this->values($totals, $firstReply, $flowId);

        if ($existing !== null) {
            $existing->update($values);

            return;
        }

        ConversationDailyMetric::query()->create($values + [
            'date' => $start->toDateString(),
            'flow_key' => $flowKey,
        ]);
    }

    /**
     * @param  array<string, int>  $totals
     * @param  array{total: int, samples: int, average: float|null}  $firstReply
     * @return array<string, mixed>
     */
    private function values(array $totals, array $firstReply, ?int $flowId): array
    {
        return [
            'conversation_flow_id' => $flowId,
            'approached' => $totals['approached'],
            'permission_granted' => $totals['permission_granted'],
            'permission_denied' => $totals['permission_denied'],
            'opted_out' => $totals['opted_out'],
            'answers_received' => $totals['answers_received'],
            'completed' => $totals['completed'],
            'waiting_human' => $totals['waiting_human'],
            'failed' => $totals['failed'],
            'automated_messages' => $totals['automated_messages'],
            'conversations_with_turns' => $totals['conversations_with_turns'],
            'first_reply_seconds_total' => $firstReply['total'],
            'first_reply_samples' => $firstReply['samples'],
            'rebuilt_at' => now(),
        ];
    }
}
