<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\InsightTopicRequest;
use App\Models\InsightTopic;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
        // array_merge, nao `+`: o operador de uniao preservaria o valor cru do
        // formulario e ignoraria estes ajustes.
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
        // obrigatorio de qualquer saida nao reconhecida do modelo.
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
            return back()->with('error', 'O tema de fallback nao pode ser excluido.');
        }

        if ($insightTopic->isInUse()) {
            return back()->with('error', 'Este tema ja foi utilizado e nao pode ser excluido. Desative-o.');
        }

        $slug = $insightTopic->slug;
        $insightTopic->delete();

        $audit->log('ai_insights.topic_deleted', 'Tema de insight excluido.', null, ['slug' => $slug], null, $request->user());

        return redirect()->route('admin.insight-topics.index')->with('success', 'Tema excluido.');
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
