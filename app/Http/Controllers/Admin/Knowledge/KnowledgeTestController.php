<?php

namespace App\Http\Controllers\Admin\Knowledge;

use App\Contracts\AnswerGroundingValidator;
use App\Contracts\KnowledgeRetriever;
use App\Data\Knowledge\RetrievalQuery;
use App\Data\Knowledge\RetrievalResult;
use App\Enums\RetrievalStrategy;
use App\Http\Controllers\Controller;
use App\Models\KnowledgeBase;
use App\Services\AuditLogger;
use App\Services\Knowledge\KnowledgeGuard;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Teste de busca e de fundamentação, sem envio.
 *
 * Nada aqui produz mensagem para ninguém. E a tela que responde a pergunta
 * "o que a base devolveria para esta consulta", que e o que permite homologar a
 * base antes de liga-la.
 */
class KnowledgeTestController extends Controller
{
    public function __construct(
        private readonly KnowledgeRetriever $retriever,
        private readonly AnswerGroundingValidator $grounding,
        private readonly KnowledgeGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->can('knowledge.test_retrieval'), 403);

        $bases = KnowledgeBase::query()->orderBy('name')->get();
        $result = null;
        $verdict = null;
        $query = null;

        if ($request->filled('query')) {
            $data = $request->validate([
                'query' => ['required', 'string', 'max:1000'],
                'base_ids' => ['required', 'array', 'min:1'],
                'base_ids.*' => ['integer', Rule::exists('knowledge_bases', 'id')],
                'strategy' => ['nullable', Rule::enum(RetrievalStrategy::class)],
                'answer' => ['nullable', 'string', 'max:2000'],
            ]);

            $query = new RetrievalQuery(
                text: $data['query'],
                baseIds: array_map('intval', $data['base_ids']),
                strategy: isset($data['strategy']) ? RetrievalStrategy::from($data['strategy']) : $this->guard->strategy(),
                topK: $this->guard->topK(),
                threshold: $this->guard->threshold(),
                maxContextChars: $this->guard->maxContextChars(),
            );

            $result = $this->retriever->retrieve($query);

            // O teste de resposta usa um texto digitado pela pessoa que administra.
            // Nenhuma chamada ao provedor de IA acontece aqui: o que se testa e a
            // validação de fundamentação, não a geração.
            if (filled($data['answer'] ?? null)) {
                $verdict = $this->grounding->validate(
                    $data['answer'],
                    $this->citationsOf($result),
                    $result,
                    true,
                );
            }

            $this->audit->log('knowledge.retrieval_tested', 'Teste de busca na base de conhecimento.', null, null, [
                'bases' => $query->baseIds,
                'strategy' => $result->strategy->value,
                'returned' => $result->count(),
            ], $request->user());
        }

        return view('admin.knowledge.test', [
            'bases' => $bases,
            'strategies' => RetrievalStrategy::cases(),
            'query' => $query,
            'result' => $result,
            'verdict' => $verdict,
            'knowledgeEnabled' => $this->guard->enabled(),
        ]);
    }

    /**
     * Cita tudo o que foi recuperado.
     *
     * No teste manual quem escreve o texto não declara citações; conferir contra
     * o conjunto inteiro responde a pergunta útil: "o que eu escrevi se sustenta
     * no que a base devolveu?".
     *
     * @return array<int, array<string, mixed>>
     */
    private function citationsOf(RetrievalResult $result): array
    {
        return array_map(
            static fn ($chunk): array => [
                'document_id' => $chunk->documentId,
                'chunk_id' => $chunk->reference(),
                'page' => $chunk->page,
                'section' => $chunk->section,
            ],
            $result->chunks,
        );
    }
}
