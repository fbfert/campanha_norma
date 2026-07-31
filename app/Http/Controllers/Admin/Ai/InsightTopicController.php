<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\InsightTopicRequest;
use App\Models\InsightTopic;
use App\Services\AuditLogger;
use App\Services\Exports\TableExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InsightTopicController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('ai_insights.view'), 403);

        return view('admin.insight-topics.index', [
            'topics' => InsightTopic::query()
                ->with('parent')
                ->withCount('insights')
                ->orderBy('display_order')
                ->orderBy('name')
                ->paginate(30),
        ]);
    }

    /**
     * Exporta a taxonomia inteira.
     *
     * A permissão e a mesma de ver a tela, e não uma mais restrita: a taxonomia
     * e vocabulário de configuração, não dado de pessoa. Quem já lê os temas na
     * tela não descobre nada de novo ao baixa-los — muda o formato, não o
     * acesso.
     *
     * Exporta tudo, sem paginação: uma taxonomia partida em páginas de trinta
     * não serve para conferir ou comparar, que e o motivo de exportar.
     */
    public function export(Request $request, TableExportService $export): BinaryFileResponse
    {
        abort_unless($request->user()->can('ai_insights.view'), 403);

        $topics = InsightTopic::query()
            ->with('parent')
            ->withCount('insights')
            ->orderBy('display_order')
            ->orderBy('name')
            ->cursor()
            ->map(fn (InsightTopic $topic): array => [
                $topic->display_order,
                $topic->name,
                $topic->slug,
                $topic->parent?->name,
                $topic->description,
                $topic->synonyms,
                $topic->color,
                $topic->insights_count,
                $topic->is_fallback ? 'sim' : 'não',
                $topic->is_active ? 'ativo' : 'inativo',
            ]);

        return $export->download(
            'temas',
            ['ordem', 'tema', 'identificador', 'tema_pai', 'descricao', 'sinonimos', 'cor', 'insights', 'fallback', 'situacao'],
            $topics,
            (string) $request->query('format', 'csv'),
            'insight_topics.exported',
            'Taxonomia de temas exportada.',
        );
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->can('ai_insights.manage_taxonomy'), 403);

        return view('admin.insight-topics.create', [
            'topic' => new InsightTopic(['display_order' => 0, 'is_active' => true]),
            'parents' => $this->parentOptions(),
        ]);
    }

    public function store(InsightTopicRequest $request, AuditLogger $audit): RedirectResponse
    {
        // array_merge, não `+`: o operador de união preservaria o valor cru do
        // formulário e ignoraria estes ajustes.
        $topic = InsightTopic::create(array_merge($request->validated(), [
            'is_active' => $request->boolean('is_active'),
            'is_fallback' => false,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]));

        $audit->log('ai_insights.topic_created', 'Tema de insight criado.', $topic, null, [
            'slug' => $topic->slug,
        ], $request->user());

        return redirect()->route('admin.insight-topics.index')->with('success', 'Tema criado.');
    }

    public function edit(Request $request, InsightTopic $insightTopic): View
    {
        abort_unless($request->user()->can('ai_insights.manage_taxonomy'), 403);

        return view('admin.insight-topics.edit', [
            'topic' => $insightTopic,
            'parents' => $this->parentOptions($insightTopic->id),
        ]);
    }

    public function update(InsightTopicRequest $request, InsightTopic $insightTopic, AuditLogger $audit): RedirectResponse
    {
        $old = $insightTopic->only(['name', 'slug', 'synonyms', 'display_order', 'is_active']);

        $data = $request->validated();

        // O tema de fallback nunca pode ser desativado: ele e o destino
        // obrigatório de qualquer saída não reconhecida do modelo.
        $isActive = $insightTopic->is_fallback ? true : $request->boolean('is_active');

        $insightTopic->update(array_merge($data, [
            'is_active' => $isActive,
            'updated_by' => $request->user()->id,
        ]));

        $audit->log('ai_insights.topic_updated', 'Tema de insight atualizado.', $insightTopic, $old, [
            'slug' => $insightTopic->slug,
        ], $request->user());

        return redirect()->route('admin.insight-topics.index')->with('success', 'Tema atualizado.');
    }

    public function destroy(Request $request, InsightTopic $insightTopic, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('ai_insights.manage_taxonomy'), 403);

        if ($insightTopic->is_fallback) {
            return back()->with('error', 'O tema de fallback não pode ser excluído.');
        }

        if ($insightTopic->isInUse()) {
            return back()->with('error', 'Este tema já foi utilizado e não pode ser excluído. Desative-o.');
        }

        $slug = $insightTopic->slug;
        $insightTopic->delete();

        $audit->log('ai_insights.topic_deleted', 'Tema de insight excluído.', null, ['slug' => $slug], null, $request->user());

        return redirect()->route('admin.insight-topics.index')->with('success', 'Tema excluído.');
    }

    private function parentOptions(?int $exceptId = null)
    {
        return InsightTopic::query()
            ->whereNull('parent_id')
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->orderBy('display_order')
            ->get();
    }
}
