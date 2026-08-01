<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\InsightTopicRequest;
use App\Models\InsightTopic;
use App\Services\AuditLogger;
use App\Services\Ai\InsightTopicImporter;
use App\Services\Exports\SqlExportService;
use App\Services\Exports\TableExportService;
use App\Services\Exports\TableImportService;
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
    public function export(Request $request, TableExportService $export, SqlExportService $sql): BinaryFileResponse
    {
        abort_unless($request->user()->can('ai_insights.view'), 403);

        $format = (string) $request->query('format', 'csv');

        if ($format === 'sql') {
            return $this->exportSql($sql);
        }

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
                // O identificador do pai, e não o nome dele: a exportação e o
                // modelo da importação, e nome não e único. Identificador e.
                $topic->parent?->slug,
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
            $format,
            'insight_topics.exported',
            'Taxonomia de temas exportada.',
        );
    }

    /**
     * A taxonomia em `INSERT`, para levar de um ambiente a outro.
     *
     * O `id` sai junto, e com ele o `parent_id`. Sem isso a hierarquia se
     * perderia: um tema filho apontaria para um número que no destino pertence a
     * outro tema. A contrapartida esta escrita no cabeçalho do arquivo — a
     * tabela de destino precisa estar vazia, senão a chave primária colide.
     */
    private function exportSql(SqlExportService $sql): BinaryFileResponse
    {
        $columns = ['id', 'parent_id', 'name', 'slug', 'description', 'synonyms', 'color', 'display_order', 'is_active', 'is_fallback', 'created_at', 'updated_at'];

        $rows = InsightTopic::query()
            // Pai antes de filho, senão a chave estrangeira recusa a linha.
            ->orderByRaw('parent_id is not null')
            ->orderBy('id')
            ->cursor()
            ->map(fn (InsightTopic $topic): array => [
                $topic->id,
                $topic->parent_id,
                $topic->name,
                $topic->slug,
                $topic->description,
                $topic->synonyms,
                $topic->color,
                $topic->display_order,
                $topic->is_active,
                $topic->is_fallback,
                $topic->created_at?->toDateTimeString(),
                $topic->updated_at?->toDateTimeString(),
            ]);

        return $sql->download(
            'temas',
            'insight_topics',
            $columns,
            $rows,
            'insight_topics.exported',
            'Taxonomia de temas exportada em SQL.',
            "O id e o parent_id saem para preservar a hierarquia, então a tabela\n"
                ."de destino precisa estar vazia. Autoria (created_by, updated_by)\n"
                .'não sai: ela aponta para usuários deste sistema.',
        );
    }

    /**
     * Importação em duas fases: conferir, depois gravar.
     *
     * Mesmo desenho da importação de contatos, e pelo mesmo motivo — quem envia
     * uma planilha não sabe o que ela vai fazer até ver escrito, linha por
     * linha, o que será criado, o que será alterado e o que será recusado.
     */
    public function import(Request $request): View
    {
        abort_unless($request->user()->can('ai_insights.manage_taxonomy'), 403);

        return view('admin.insight-topics.import', ['plan' => null, 'stored' => null]);
    }

    public function importPreview(Request $request, TableImportService $files, InsightTopicImporter $importer): View
    {
        abort_unless($request->user()->can('ai_insights.manage_taxonomy'), 403);

        $request->validate(['file' => ['required', 'file', 'max:5120']]);

        $stored = $files->stash($request->file('file'));
        $plan = $importer->plan($files->read($stored));

        // O identificador do arquivo fica na sessão, e não so no formulário.
        // Sem isso, quem descobrisse o identificador de outra pessoa poderia
        // mandar gravar o arquivo dela.
        $request->session()->put('insight_topics.import', $stored);

        return view('admin.insight-topics.import', ['plan' => $plan, 'stored' => $stored]);
    }

    public function importConfirm(Request $request, TableImportService $files, InsightTopicImporter $importer): RedirectResponse
    {
        abort_unless($request->user()->can('ai_insights.manage_taxonomy'), 403);

        $stored = (string) $request->session()->pull('insight_topics.import');

        if ($stored === '' || $stored !== $request->input('stored')) {
            return redirect()
                ->route('admin.insight-topics.import')
                ->withErrors(['file' => 'A conferência expirou. Envie o arquivo novamente.']);
        }

        $summary = $importer->apply($importer->plan($files->read($stored)), $request->user());
        $files->discard($stored);

        return redirect()->route('admin.insight-topics.index')->with(
            'success',
            "Importação concluída: {$summary['criados']} criados, {$summary['atualizados']} atualizados, {$summary['ignorados']} ignorados."
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
