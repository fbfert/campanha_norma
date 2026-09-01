<?php

namespace App\Services\Analytics;

use App\Enums\InsightUrgency;
use App\Enums\KnowledgeBaseStatus;
use App\Enums\KnowledgeDocumentStatus;
use App\Models\ConversationInsight;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sobre o que a população falou e a campanha ainda não escreveu.
 *
 * A 9D guarda os documentos aprovados; a 9B guarda os temas que apareceram.
 * Ninguém tinha cruzado os dois, e o cruzamento é a pauta: o buraco mais caro
 * é o tema mais citado sem nenhuma posição escrita.
 *
 * **Este serviço fica fora da camada de recuperação da 9D, de propósito.**
 * Aquela camada tem uma trava estrutural — um teste lê o código dela, sem os
 * comentários, e falha se as tabelas de conversa ou de insight aparecerem. A
 * razão é que a opinião coletada nunca pode virar fonte de resposta oficial.
 * Aqui a leitura é a inversa e inofensiva: não é o insight decidindo o que
 * responder, é o insight apontando o que ainda não foi respondido.
 */
class PositioningGapService
{
    /**
     * Temas mencionados no período, com a contagem de documentos aprovados.
     *
     * Buraco é tema com menção e nenhum documento **aprovado** em base
     * **ativa**. As duas condições são deliberadas: indexar não aprova — a
     * separação entre pronto e aprovado já existe na 9D e significa que alguém
     * decidiu que aquilo pode ser dito —, e documento aprovado em base
     * desligada não responde a ninguém.
     *
     * @return array<int, array<string, mixed>>
     */
    public function gaps(Carbon $from, Carbon $to, ?int $flowId = null): array
    {
        $temas = ConversationInsight::query()
            ->join('insight_topics', 'insight_topics.id', '=', 'conversation_insights.insight_topic_id')
            ->whereBetween('conversation_insights.created_at', [$from, $to])
            ->when($flowId, fn ($query) => $query->where('conversation_insights.conversation_flow_id', $flowId))
            ->select(
                'insight_topics.id',
                'insight_topics.name',
                'insight_topics.response_guidance',
                'insight_topics.red_lines',
                DB::raw('count(*) as mentions'),
            )
            ->groupBy('insight_topics.id', 'insight_topics.name', 'insight_topics.response_guidance', 'insight_topics.red_lines')
            ->orderByDesc('mentions')
            ->get();

        if ($temas->isEmpty()) {
            return [];
        }

        $aprovados = $this->approvedDocumentCounts($temas->pluck('id')->all(), $flowId);
        $urgencias = $this->predominantUrgency($from, $to, $flowId, $temas->pluck('id')->all());

        return $temas
            ->map(fn ($tema): array => [
                'topic_id' => (int) $tema->id,
                'name' => (string) $tema->name,
                'mentions' => (int) $tema->mentions,
                'urgency' => $urgencias[(int) $tema->id] ?? null,
                'approved_documents' => $aprovados[(int) $tema->id] ?? 0,
                'has_guidance' => filled($tema->response_guidance),
                'has_red_lines' => filled($tema->red_lines),
                // Zero é o achado. O nome do campo diz o que ele é, e a tela
                // destaca a linha em vez de escondê-la numa coluna a mais.
                'is_gap' => ($aprovados[(int) $tema->id] ?? 0) === 0,
            ])
            ->all();
    }

    /**
     * Documentos aprovados, em base ativa, apontando para cada tema.
     *
     * Quando há fluxo, contam apenas as bases associadas a ele: um documento
     * aprovado numa base que aquele fluxo não consulta não responde a quem
     * respondeu àquela pesquisa.
     *
     * @param  array<int, int>  $topicIds
     * @return array<int, int>
     */
    private function approvedDocumentCounts(array $topicIds, ?int $flowId): array
    {
        return DB::table('knowledge_documents')
            ->join('knowledge_bases', 'knowledge_bases.id', '=', 'knowledge_documents.knowledge_base_id')
            ->whereIn('knowledge_documents.insight_topic_id', $topicIds)
            ->where('knowledge_documents.status', KnowledgeDocumentStatus::Approved->value)
            ->where('knowledge_bases.status', KnowledgeBaseStatus::Active->value)
            ->when($flowId, fn ($query) => $query->whereIn(
                'knowledge_bases.id',
                DB::table('conversation_flow_knowledge_base')
                    ->where('conversation_flow_id', $flowId)
                    ->select('knowledge_base_id'),
            ))
            ->select('knowledge_documents.insight_topic_id', DB::raw('count(*) as total'))
            ->groupBy('knowledge_documents.insight_topic_id')
            ->pluck('total', 'insight_topic_id')
            ->map(fn ($total): int => (int) $total)
            ->all();
    }

    /**
     * A urgência que mais apareceu em cada tema.
     *
     * @param  array<int, int>  $topicIds
     * @return array<int, string>
     */
    private function predominantUrgency(Carbon $from, Carbon $to, ?int $flowId, array $topicIds): array
    {
        $linhas = ConversationInsight::query()
            ->whereBetween('conversation_insights.created_at', [$from, $to])
            ->when($flowId, fn ($query) => $query->where('conversation_insights.conversation_flow_id', $flowId))
            ->whereIn('insight_topic_id', $topicIds)
            ->whereNotNull('urgency')
            ->select('insight_topic_id', 'urgency', DB::raw('count(*) as total'))
            ->groupBy('insight_topic_id', 'urgency')
            ->orderByDesc('total')
            ->get();

        $predominante = [];

        foreach ($linhas as $linha) {
            $tema = (int) $linha->insight_topic_id;

            // A consulta já vem ordenada pela contagem: a primeira urgência que
            // aparece para o tema é a que mais apareceu.
            //
            // O modelo converte `urgency` para enum, e a conversão vale também
            // nesta consulta agregada. Forçar `(string)` sobre o enum estoura,
            // e o erro só aparece quando existe insight com urgência gravada —
            // nunca numa base de teste recém-criada.
            $urgencia = $linha->urgency;

            $predominante[$tema] ??= $urgencia instanceof InsightUrgency
                ? $urgencia->value
                : (string) $urgencia;
        }

        return $predominante;
    }
}
