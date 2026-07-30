<?php

namespace App\Services\Knowledge;

use App\Contracts\KnowledgeRetriever;
use App\Data\Knowledge\RetrievalQuery;
use App\Data\Knowledge\RetrievalResult;
use App\Data\Knowledge\RetrievedChunk;
use App\Enums\KnowledgeBaseStatus;
use App\Enums\KnowledgeDocumentStatus;
use App\Enums\RetrievalStrategy;
use App\Exceptions\Knowledge\KnowledgeProviderException;
use App\Models\KnowledgeChunk;
use App\Services\SystemSettingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Recuperacao sobre o armazenamento relacional.
 *
 * Esta classe consulta exclusivamente `knowledge_chunks`, `knowledge_documents` e
 * `knowledge_bases`. Nao referencia `Conversation`, `ConversationMessage`,
 * `Contact` nem `ConversationInsight`, e um teste le este arquivo para garantir
 * que continue assim: a proibicao de usar conversa de terceiro ou opiniao da
 * populacao como fonte precisa ser estrutural, nao apenas documentada.
 */
class LocalKnowledgeRetriever implements KnowledgeRetriever
{
    public function __construct(
        private readonly TextNormalizer $normalizer,
        private readonly KnowledgeProviderManager $providers,
        private readonly SystemSettingService $settings,
    ) {}

    public function retrieve(RetrievalQuery $query): RetrievalResult
    {
        if (! $query->hasBases()) {
            return RetrievalResult::empty($query->strategy, 'sem_base_associada');
        }

        $terms = $this->normalizer->terms($query->text);

        if ($terms === [] && ! $query->strategy->usesEmbeddings()) {
            return RetrievalResult::empty($query->strategy, 'consulta_sem_termos');
        }

        $startedAt = microtime(true);

        $lexical = $query->strategy->usesLexical() ? $this->lexicalScores($query, $terms) : [];
        $vectorOutcome = $query->strategy->usesEmbeddings()
            ? $this->vectorScores($query)
            : ['scores' => [], 'degraded' => null, 'candidates' => 0];

        $scores = $this->combine($query->strategy, $lexical, $vectorOutcome['scores']);
        $candidateCount = max(count($lexical), (int) $vectorOutcome['candidates']);

        if ($scores === []) {
            return new RetrievalResult(
                [],
                $query->strategy,
                $candidateCount,
                (int) round((microtime(true) - $startedAt) * 1000),
                $vectorOutcome['degraded'],
            );
        }

        arsort($scores);

        $chunks = $this->materialize($query, $scores);

        return new RetrievalResult(
            $chunks,
            $query->strategy,
            $candidateCount,
            (int) round((microtime(true) - $startedAt) * 1000),
            $vectorOutcome['degraded'],
        );
    }

    /**
     * Pontuacao lexica em 0..1.
     *
     * Cobertura pesa mais que frequencia de proposito: um trecho que menciona
     * todos os termos uma vez responde melhor do que um que repete um unico termo
     * dez vezes.
     *
     * @param  array<int, string>  $terms
     * @return array<int, float> chunk id => score
     */
    private function lexicalScores(RetrievalQuery $query, array $terms): array
    {
        if ($terms === []) {
            return [];
        }

        $limit = max(1, (int) $this->settings->get('knowledge.max_lexical_candidates', 2000));

        $candidates = $this->baseQuery($query)
            ->where(function (Builder $inner) use ($terms): void {
                foreach ($terms as $term) {
                    $inner->orWhere('knowledge_chunks.search_text', 'like', '%'.$term.'%');
                }
            })
            ->limit($limit)
            ->get(['knowledge_chunks.id', 'knowledge_chunks.search_text']);

        $scores = [];
        $distinct = count($terms);

        foreach ($candidates as $candidate) {
            $haystack = (string) $candidate->search_text;
            $found = 0;
            $occurrences = 0;
            $positions = [];

            foreach ($terms as $term) {
                $count = mb_substr_count($haystack, $term);

                if ($count === 0) {
                    continue;
                }

                $found++;
                $occurrences += $count;
                $position = mb_strpos($haystack, $term);

                if ($position !== false) {
                    $positions[] = $position;
                }
            }

            if ($found === 0) {
                continue;
            }

            $coverage = $found / $distinct;
            $density = min(1.0, $occurrences / max(1, $distinct * 2));
            $proximity = $this->proximity($positions);

            $scores[(int) $candidate->id] = round(
                (0.60 * $coverage) + (0.25 * $density) + (0.15 * $proximity),
                6
            );
        }

        return $scores;
    }

    /**
     * Termos proximos indicam que o trecho fala do assunto, nao que ele menciona
     * as palavras em contextos distantes.
     *
     * @param  array<int, int>  $positions
     */
    private function proximity(array $positions): float
    {
        if (count($positions) < 2) {
            return 1.0;
        }

        $window = max(1, (int) $this->settings->get('knowledge.proximity_window', 400));
        $spread = max($positions) - min($positions);

        return $spread <= $window ? 1.0 : max(0.0, 1.0 - (($spread - $window) / ($window * 4)));
    }

    /**
     * Pontuacao vetorial por cosseno.
     *
     * Valores negativos sao zerados: cosseno negativo significa "fala de outra
     * coisa", nao "fala do contrario", e tratar isso como pontuacao negativa
     * distorceria a fusao hibrida.
     *
     * @return array{scores: array<int, float>, degraded: ?string, candidates: int}
     */
    private function vectorScores(RetrievalQuery $query): array
    {
        $embeddings = $this->providers->embeddings();

        if (! $embeddings->isConfigured()) {
            return ['scores' => [], 'degraded' => 'embeddings_nao_configurados', 'candidates' => 0];
        }

        $candidateCount = $this->baseQuery($query)->whereNotNull('knowledge_chunks.embedding')->count();
        $maximum = max(1, (int) $this->settings->get('knowledge.max_vector_candidates', 5000));

        if ($candidateCount > $maximum) {
            /*
             | Teto explicito do ADR 0001. Acima dele a busca vetorial nao degrada
             | em silencio: recusa, registra e deixa a estrategia lexica responder.
             */
            Log::warning('knowledge.vector_candidate_limit', [
                'candidates' => $candidateCount,
                'maximum' => $maximum,
            ]);

            return ['scores' => [], 'degraded' => 'limite_de_candidatos_excedido', 'candidates' => $candidateCount];
        }

        try {
            $vectors = $embeddings->embed([$query->text]);
        } catch (KnowledgeProviderException $exception) {
            Log::warning('knowledge.embedding_failed', ['error_code' => $exception->errorCode]);

            return ['scores' => [], 'degraded' => $exception->errorCode, 'candidates' => $candidateCount];
        }

        $needle = $vectors[0] ?? [];

        if ($needle === []) {
            return ['scores' => [], 'degraded' => 'consulta_sem_vetor', 'candidates' => $candidateCount];
        }

        $dimensions = count($needle);
        $needleNorm = $this->norm($needle);

        if ($needleNorm === 0.0) {
            return ['scores' => [], 'degraded' => 'consulta_sem_vetor', 'candidates' => $candidateCount];
        }

        $scores = [];
        $mismatches = 0;

        $this->baseQuery($query)
            ->whereNotNull('knowledge_chunks.embedding')
            ->select(['knowledge_chunks.id', 'knowledge_chunks.embedding', 'knowledge_chunks.embedding_dimensions'])
            ->chunkById(500, function ($rows) use (&$scores, &$mismatches, $needle, $needleNorm, $dimensions): void {
                foreach ($rows as $row) {
                    // Dimensao divergente e ignorada, nao adivinhada: comparar
                    // vetores de modelos diferentes produz numero sem sentido.
                    if ((int) $row->embedding_dimensions !== $dimensions) {
                        $mismatches++;

                        continue;
                    }

                    $vector = KnowledgeChunk::unpackEmbedding($row->embedding);

                    if (count($vector) !== $dimensions) {
                        $mismatches++;

                        continue;
                    }

                    $norm = $this->norm($vector);

                    if ($norm === 0.0) {
                        continue;
                    }

                    $dot = 0.0;
                    foreach ($vector as $position => $value) {
                        $dot += $value * $needle[$position];
                    }

                    $cosine = $dot / ($norm * $needleNorm);
                    $scores[(int) $row->id] = round(max(0.0, $cosine), 6);
                }
            }, 'knowledge_chunks.id', 'id');

        if ($mismatches > 0) {
            Log::warning('knowledge.embedding_dimension_mismatch', [
                'ignored' => $mismatches,
                'expected' => $dimensions,
            ]);
        }

        return ['scores' => $scores, 'degraded' => null, 'candidates' => $candidateCount];
    }

    /**
     * Fusao das duas estrategias.
     *
     * Um trecho forte em qualquer uma das duas sobrevive; concordancia entre elas
     * ganha um bonus pequeno. Evita que a hibrida seja pior que as partes, que e o
     * que uma media simples produziria.
     *
     * @param  array<int, float>  $lexical
     * @param  array<int, float>  $vector
     * @return array<int, float>
     */
    private function combine(RetrievalStrategy $strategy, array $lexical, array $vector): array
    {
        if ($strategy === RetrievalStrategy::Lexical) {
            return $lexical;
        }

        if ($strategy === RetrievalStrategy::Vector) {
            return $vector;
        }

        // Hibrida sem vetor disponivel vale exatamente a lexica.
        if ($vector === []) {
            return $lexical;
        }

        $combined = [];

        foreach (array_unique(array_merge(array_keys($lexical), array_keys($vector))) as $id) {
            $lex = $lexical[$id] ?? 0.0;
            $vec = $vector[$id] ?? 0.0;

            $combined[$id] = round(min(1.0, max($lex, $vec) + (0.10 * min($lex, $vec))), 6);
        }

        return $combined;
    }

    /**
     * Aplica threshold, top_k, deduplicacao e limite de contexto.
     *
     * @param  array<int, float>  $scores  ja ordenado do maior para o menor
     * @return array<int, RetrievedChunk>
     */
    private function materialize(RetrievalQuery $query, array $scores): array
    {
        $eligible = array_filter($scores, fn (float $score): bool => $score >= $query->threshold);

        if ($eligible === []) {
            return [];
        }

        $ids = array_slice(array_keys($eligible), 0, max($query->topK, 1) * 3, true);

        $rows = KnowledgeChunk::query()
            ->join('knowledge_documents', 'knowledge_documents.id', '=', 'knowledge_chunks.knowledge_document_id')
            ->whereIn('knowledge_chunks.id', $ids)
            ->get([
                'knowledge_chunks.id',
                'knowledge_chunks.knowledge_document_id',
                'knowledge_chunks.knowledge_base_id',
                'knowledge_chunks.content',
                'knowledge_chunks.content_hash',
                'knowledge_chunks.page',
                'knowledge_chunks.section',
                'knowledge_chunks.external_chunk_id',
                'knowledge_documents.title as document_title',
                'knowledge_documents.version as document_version',
            ])
            ->keyBy('id');

        $chunks = [];
        $seenHashes = [];
        $usedChars = 0;

        foreach (array_keys($eligible) as $id) {
            if (count($chunks) >= $query->topK) {
                break;
            }

            $row = $rows->get($id);

            if (! $row) {
                continue;
            }

            // Deduplicacao por conteudo: o mesmo paragrafo em dois documentos nao
            // vale duas evidencias.
            $hash = (string) $row->content_hash;
            if (in_array($hash, $seenHashes, true)) {
                continue;
            }

            $content = (string) $row->content;
            $length = mb_strlen($content);

            if ($usedChars + $length > $query->maxContextChars) {
                if ($chunks !== []) {
                    break;
                }

                // O primeiro trecho e truncado em vez de descartado: devolver
                // vazio por excesso de tamanho seria pior que devolver o inicio.
                $content = mb_substr($content, 0, $query->maxContextChars);
                $length = mb_strlen($content);
            }

            $seenHashes[] = $hash;
            $usedChars += $length;

            $chunks[] = new RetrievedChunk(
                chunkId: (int) $row->id,
                documentId: (int) $row->knowledge_document_id,
                baseId: (int) $row->knowledge_base_id,
                documentTitle: (string) $row->document_title,
                documentVersion: (int) $row->document_version,
                content: $content,
                score: (float) $eligible[$id],
                page: $row->page === null ? null : (int) $row->page,
                section: $row->section === null ? null : (string) $row->section,
                externalChunkId: $row->external_chunk_id === null ? null : (string) $row->external_chunk_id,
            );
        }

        return $chunks;
    }

    /**
     * Filtro obrigatorio: base associada e ativa, documento aprovado.
     *
     * A condicao e reafirmada aqui mesmo existindo no escopo do model. Duplicar
     * uma regra dessa natureza e barato; esquece-la em um caminho novo custa uma
     * resposta baseada em documento nao aprovado.
     */
    private function baseQuery(RetrievalQuery $query): Builder
    {
        return KnowledgeChunk::query()
            ->join('knowledge_documents', 'knowledge_documents.id', '=', 'knowledge_chunks.knowledge_document_id')
            ->join('knowledge_bases', 'knowledge_bases.id', '=', 'knowledge_chunks.knowledge_base_id')
            ->whereIn('knowledge_chunks.knowledge_base_id', $query->baseIds)
            ->where('knowledge_documents.status', KnowledgeDocumentStatus::Approved->value)
            ->where('knowledge_bases.status', KnowledgeBaseStatus::Active->value);
    }

    /**
     * @param  array<int, float>  $vector
     */
    private function norm(array $vector): float
    {
        $sum = 0.0;

        foreach ($vector as $value) {
            $sum += $value * $value;
        }

        return sqrt($sum);
    }
}
