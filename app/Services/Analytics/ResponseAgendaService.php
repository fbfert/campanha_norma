<?php

namespace App\Services\Analytics;

use App\Enums\ConversationMessageDirection;
use App\Enums\InsightUrgency;
use App\Enums\KnowledgeBaseStatus;
use App\Enums\KnowledgeDocumentStatus;
use App\Models\ConversationInsight;
use App\Models\ConversationMessage;
use App\Services\SystemSettingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A fila de quem responder e o dossiê de cada pessoa.
 *
 * Duzentas respostas cabem em atendimento individual: lido o dossiê, uma
 * resposta gravada à mão leva dois minutos. Não há escassez a administrar, e
 * por isso este serviço **ordena por relevância** em vez de priorizar por
 * escassez. Toda pessoa da fila é para responder; o peso só decide quem vem
 * antes.
 *
 * O dossiê é montagem determinística de campos já gravados. Nenhuma chamada de
 * modelo acontece aqui, e isso não é economia: é que a citação literal do que o
 * eleitor escreveu é mais forte que qualquer paráfrase, e uma promessa dita
 * pela candidata na voz dela não tem retratação possível.
 *
 * **Não há supressão de célula neste serviço.** Ela protegeria contra
 * identificar alguém a partir de um agregado pequeno, e aqui o relatório é
 * nominal por natureza — identificar é o ponto. É exatamente por isso que a
 * pauta exige permissão própria, somada à de identificação e à de conteúdo, e
 * mora em módulo separado do painel agregado. As duas regras são opostas, e
 * mantê-las no mesmo lugar é onde o vazamento nasce.
 */
class ResponseAgendaService
{
    public function __construct(
        private readonly SystemSettingService $settings,
        private readonly TopicMetricsService $topics,
    ) {}

    /**
     * A fila de pessoas a responder, ordenada por prioridade.
     *
     * @param  array{topic_id?: int|null, city?: string|null, state?: string|null}  $filters
     * @return array<int, array<string, mixed>>
     */
    public function queue(Carbon $from, Carbon $to, ?int $flowId = null, array $filters = []): array
    {
        $insights = ConversationInsight::query()
            ->whereBetween('conversation_insights.created_at', [$from, $to])
            ->when($flowId, fn ($query) => $query->where('conversation_insights.conversation_flow_id', $flowId))
            ->when($filters['topic_id'] ?? null, fn ($query, $tema) => $query->where('insight_topic_id', $tema))
            ->when($filters['city'] ?? null, fn ($query, $cidade) => $query->whereHas(
                'contact',
                fn ($contato) => $contato->where('city', $cidade),
            ))
            ->with(['contact', 'sourceMessage', 'topic', 'answeredBy'])
            ->get();

        $emergentes = $this->emergingTopicIds($from, $to, $flowId);
        $detectadas = $this->detectedAnswers($insights);

        $linhas = $insights->map(function (ConversationInsight $insight) use ($emergentes, $detectadas): array {
            $marca = $this->answeredMark($insight, $detectadas[$insight->id] ?? null);

            return [
                'insight_id' => $insight->id,
                'conversation_id' => $insight->conversation_id,
                'name' => $insight->contact?->first_name ?: $insight->contact?->name,
                'city' => $insight->contact?->city,
                'topic' => $insight->topic?->name,
                'topic_id' => $insight->insight_topic_id,
                'urgency' => $insight->urgency,
                'excerpt' => $this->excerpt($this->sentence($insight), 120),
                'priority' => $this->priority($insight, $emergentes),
                'answered' => $marca !== null,
                'answered_source' => $marca['source'] ?? null,
                'answered_at' => $marca['at'] ?? null,
                'answered_by' => $marca['by'] ?? null,
            ];
        });

        $estado = $filters['state'] ?? null;

        if ($estado === 'respondida') {
            $linhas = $linhas->where('answered', true);
        } elseif ($estado === 'pendente') {
            $linhas = $linhas->where('answered', false);
        }

        return $linhas
            ->sort(fn (array $a, array $b): int => [$b['priority'], $b['insight_id']] <=> [$a['priority'], $a['insight_id']])
            ->values()
            ->all();
    }

    /**
     * O dossiê de uma pessoa, montado por composição.
     *
     * Cada bloco tem origem declarada, e nenhuma delas é um modelo: a frase sai
     * da mensagem, os campos saem da extração da 9B, a orientação e a linha
     * vermelha saem do tema e o trecho oficial sai da base da 9D.
     *
     * @return array<string, mixed>
     */
    public function dossier(ConversationInsight $insight): array
    {
        $insight->loadMissing(['contact', 'sourceMessage', 'topic', 'answeredBy']);

        $limiar = (float) $this->settings->get('analytics.low_confidence_threshold', 0.70);
        $tema = $insight->topic;
        $marca = $this->answeredMark($insight, $this->detectedAnswers(collect([$insight]))[$insight->id] ?? null);

        return [
            'insight' => $insight,
            'name' => $insight->contact?->first_name ?: $insight->contact?->name,
            'city' => $insight->contact?->city,
            'state' => $insight->contact?->state,
            'sentence' => $this->sentence($insight),
            'declared_locality' => $insight->locality_text,
            'topic' => $tema?->name,
            'urgency' => $insight->urgency,
            'sentiment' => $insight->sentiment,
            'identified_problem' => $insight->identified_problem,
            'suggested_action' => $insight->suggested_action,
            'desired_result' => $insight->desired_result,
            'response_guidance' => $tema?->response_guidance,
            'red_lines' => $tema?->red_lines,
            'official_excerpt' => $tema === null ? null : $this->officialExcerpt($tema->id),
            // O aviso é o bloco que evita alguém responder em cima de uma
            // leitura que o próprio sistema considera duvidosa.
            'low_confidence' => $insight->confidence !== null && $insight->confidence < $limiar,
            'confidence' => $insight->confidence,
            'answered' => $marca !== null,
            'answered_source' => $marca['source'] ?? null,
            'answered_at' => $marca['at'] ?? null,
            'answered_by' => $marca['by'] ?? null,
        ];
    }

    /**
     * A pontuação que ordena a fila.
     *
     * Três sinais, com pesos em configuração porque nenhum deles foi calibrado
     * com dado real. As faixas de tamanho são faixas, e não proporção contínua,
     * para uma única resposta muito longa não dominar a fila inteira.
     *
     * @param  array<int, int>  $emergingTopicIds
     */
    public function priority(ConversationInsight $insight, array $emergingTopicIds = []): int
    {
        $pesoUrgencia = (int) $this->settings->get('pauta.priority_weight_urgency', 3);
        $pesoTamanho = (int) $this->settings->get('pauta.priority_weight_length', 1);
        $pesoEmergente = (int) $this->settings->get('pauta.priority_weight_emerging', 2);

        $urgencia = match ($insight->urgency) {
            InsightUrgency::High => 2,
            InsightUrgency::Medium => 1,
            default => 0,
        };

        $caracteres = mb_strlen($this->sentence($insight));
        $tamanho = match (true) {
            $caracteres > 240 => 2,
            $caracteres > 80 => 1,
            default => 0,
        };

        $emergente = in_array((int) $insight->insight_topic_id, $emergingTopicIds, true) ? 1 : 0;

        return $pesoUrgencia * $urgencia + $pesoTamanho * $tamanho + $pesoEmergente * $emergente;
    }

    /**
     * Quem marcou a resposta, e por qual caminho.
     *
     * A marcação manual tem precedência: ela é a afirmação de uma pessoa. A
     * detecção afirma que saiu um áudio naquela conversa depois do insight, o
     * que é evidência forte e não prova — por isso a fila mostra qual das duas
     * marcou, com a data. Origem diferente é confiança diferente, e quem lê
     * precisa saber a diferença.
     *
     * @return array{source: string, at: Carbon|null, by: string|null}|null
     */
    public function answeredMark(ConversationInsight $insight, ?Carbon $detectada = null): ?array
    {
        if ($insight->answered_at !== null) {
            return [
                'source' => 'manual',
                'at' => $insight->answered_at,
                'by' => $insight->answeredBy?->name,
            ];
        }

        if ($detectada !== null) {
            return ['source' => 'sincronizacao', 'at' => $detectada, 'by' => null];
        }

        return null;
    }

    /**
     * Respostas detectadas pelo que a sincronização já grava.
     *
     * Se a candidata gravar o áudio na mesma conta pareada ao sistema, ele
     * chega na próxima sincronização como saída com mídia, e a fila se marca
     * sozinha. Nenhum botão, nenhuma disciplina exigida dela — e disciplina é o
     * que não sobrevive à terceira semana de campanha.
     *
     * **Condição:** se ela responder de outro número, isto não funciona. Por
     * isso a marcação manual existe de qualquer forma, e por isso o aviso da
     * condição está na tela da fila, não só na documentação. Condição que só o
     * manual conhece é condição que ninguém conhece.
     *
     * **Proibição:** a regra não pode usar `conversation_messages.origin`. A
     * coluna tem valor padrão `manual` e o serviço de sincronização não a
     * preenche ao criar a mensagem — uma mensagem vinda do WhatsApp Web fica
     * gravada como `manual`. Filtrar por `sync` pareceria mais preciso e não
     * casaria com nada, em silêncio. O que a sincronização escreve de verdade é
     * direção, mídia e instante de envio, e é sobre isso que a regra decide.
     *
     * Nada aqui é fila nem agendamento: é consulta sobre o que já está gravado.
     *
     * @param  Collection<int, ConversationInsight>  $insights
     * @return array<int, Carbon>
     */
    public function detectedAnswers($insights): array
    {
        $conversas = $insights->pluck('conversation_id')->filter()->unique()->values()->all();

        if ($conversas === []) {
            return [];
        }

        $dias = max(1, (int) $this->settings->get('pauta.answered_lookback_days', 30));

        $saidas = ConversationMessage::query()
            ->whereIn('conversation_id', $conversas)
            ->where('direction', ConversationMessageDirection::Outgoing)
            ->where('has_media', true)
            ->whereNotNull('sent_at')
            ->orderBy('sent_at')
            ->get(['conversation_id', 'sent_at'])
            ->groupBy('conversation_id');

        $detectadas = [];

        foreach ($insights as $insight) {
            $nascimento = $insight->created_at;

            if ($nascimento === null) {
                continue;
            }

            $limite = $nascimento->copy()->addDays($dias);

            foreach ($saidas->get($insight->conversation_id, collect()) as $saida) {
                // Depois do insight e dentro da janela. Saída anterior responde
                // a outra coisa, e saída fora da janela é uma conversa que
                // continuou por outro motivo.
                if ($saida->sent_at->gt($nascimento) && $saida->sent_at->lte($limite)) {
                    $detectadas[$insight->id] = $saida->sent_at;

                    break;
                }
            }
        }

        return $detectadas;
    }

    /**
     * Temas que apareceram neste período e não no anterior.
     *
     * O período anterior tem a mesma duração e termina onde este começa, para a
     * comparação não depender do calendário.
     *
     * @return array<int, int>
     */
    private function emergingTopicIds(Carbon $from, Carbon $to, ?int $flowId): array
    {
        $duracao = max(1, $from->diffInDays($to));
        $anteriorAte = $from->copy()->subSecond();
        $anteriorDe = $anteriorAte->copy()->subDays($duracao);

        return collect($this->topics->emerging($from, $to, $anteriorDe, $anteriorAte, $flowId))
            ->pluck('topic_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Um trecho do documento oficial que responde ao tema.
     *
     * Só documento aprovado, em base ativa. Indexar não aprova: a separação
     * existe justamente porque alguém precisa ter decidido que aquilo pode ser
     * dito a uma pessoa.
     */
    private function officialExcerpt(int $topicId): ?string
    {
        $trecho = DB::table('knowledge_chunks')
            ->join('knowledge_documents', 'knowledge_documents.id', '=', 'knowledge_chunks.knowledge_document_id')
            ->join('knowledge_bases', 'knowledge_bases.id', '=', 'knowledge_documents.knowledge_base_id')
            ->where('knowledge_documents.insight_topic_id', $topicId)
            ->where('knowledge_documents.status', KnowledgeDocumentStatus::Approved->value)
            ->where('knowledge_bases.status', KnowledgeBaseStatus::Active->value)
            ->orderBy('knowledge_chunks.id')
            ->value('knowledge_chunks.content');

        return $trecho === null ? null : $this->excerpt((string) $trecho, 600);
    }

    /**
     * A frase literal da pessoa.
     *
     * Sai do corpo da mensagem de origem, sem paráfrase. Trocá-la por um resumo
     * substituiria o que o eleitor escreveu pelo que o sistema achou que ele
     * quis dizer.
     */
    private function sentence(ConversationInsight $insight): string
    {
        return trim((string) ($insight->sourceMessage?->body ?? ''));
    }

    /**
     * Corta no espaço, nunca no meio da palavra.
     *
     * Vale para a coluna da fila e para o trecho oficial. O dossiê mostra a
     * frase inteira: lá a página é da pessoa e o texto cabe.
     */
    private function excerpt(string $texto, int $limite): string
    {
        if (mb_strlen($texto) <= $limite) {
            return $texto;
        }

        $cortado = mb_substr($texto, 0, $limite);
        $ultimoEspaco = mb_strrpos($cortado, ' ');

        if ($ultimoEspaco !== false && $ultimoEspaco > 0) {
            $cortado = mb_substr($cortado, 0, $ultimoEspaco);
        }

        return rtrim($cortado, " \t\n\r\0\x0B.,;:").'…';
    }
}
