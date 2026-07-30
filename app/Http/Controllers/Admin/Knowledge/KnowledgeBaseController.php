<?php

namespace App\Http\Controllers\Admin\Knowledge;

use App\Enums\KnowledgeBaseStatus;
use App\Enums\KnowledgeDocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\ConversationFlow;
use App\Models\KnowledgeBase;
use App\Services\AuditLogger;
use App\Services\Knowledge\KnowledgeGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administracao das bases de conhecimento.
 *
 * Ativar uma base e o ato que a torna alcancavel pela busca. Por isso ele e
 * separado da edicao e registrado em auditoria.
 */
class KnowledgeBaseController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request, KnowledgeGuard $guard): View
    {
        abort_unless($request->user()->can('knowledge.view'), 403);

        $bases = KnowledgeBase::query()
            ->withCount([
                'documents',
                'documents as approved_documents_count' => fn ($query) => $query->where('status', KnowledgeDocumentStatus::Approved->value),
            ])
            ->orderBy('name')
            ->paginate(20);

        return view('admin.knowledge.bases.index', [
            'bases' => $bases,
            'knowledgeEnabled' => $guard->enabled(),
            'strategy' => $guard->strategy(),
        ]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->can('knowledge.manage_bases'), 403);

        return view('admin.knowledge.bases.form', [
            'base' => new KnowledgeBase(['status' => KnowledgeBaseStatus::Draft, 'version' => 1]),
            'flows' => ConversationFlow::query()->orderBy('name')->get(),
            'selectedFlows' => [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('knowledge.manage_bases'), 403);

        $data = $this->validated($request);

        $base = KnowledgeBase::create($data + [
            'slug' => $this->uniqueSlug($data['name']),
            // Base nova nasce em rascunho, sempre: existir nao e o mesmo que
            // estar publicada para uso.
            'status' => KnowledgeBaseStatus::Draft,
            'version' => 1,
            'provider' => (string) config('knowledge.provider'),
            'created_by' => $request->user()->id,
        ]);

        $base->flows()->sync($this->flowPivot($request));

        $this->audit->log('knowledge_base.created', 'Base de conhecimento criada.', $base, null, $data, $request->user());

        return redirect()->route('admin.knowledge.bases.show', $base)->with('status', 'Base criada em rascunho.');
    }

    public function show(Request $request, KnowledgeBase $base): View
    {
        abort_unless($request->user()->can('knowledge.view'), 403);

        $base->load('flows');

        return view('admin.knowledge.bases.show', [
            'base' => $base,
            'documents' => $base->documents()->latest('id')->paginate(20),
            'statuses' => KnowledgeDocumentStatus::cases(),
        ]);
    }

    public function edit(Request $request, KnowledgeBase $base): View
    {
        abort_unless($request->user()->can('knowledge.manage_bases'), 403);

        return view('admin.knowledge.bases.form', [
            'base' => $base,
            'flows' => ConversationFlow::query()->orderBy('name')->get(),
            'selectedFlows' => $base->flows()->pluck('conversation_flows.id')->all(),
        ]);
    }

    public function update(Request $request, KnowledgeBase $base): RedirectResponse
    {
        abort_unless($request->user()->can('knowledge.manage_bases'), 403);

        $data = $this->validated($request);
        $before = $base->only(array_keys($data));

        $base->update($data + ['updated_by' => $request->user()->id]);
        $base->flows()->sync($this->flowPivot($request));

        $this->audit->log('knowledge_base.updated', 'Base de conhecimento atualizada.', $base, $before, $data, $request->user());

        return redirect()->route('admin.knowledge.bases.show', $base)->with('status', 'Base atualizada.');
    }

    /**
     * Mudanca de situacao da base.
     *
     * Sai da edicao de proposito: ativar uma base muda o que a IA pode afirmar,
     * e isso nao pode ser efeito colateral de salvar um formulario.
     */
    public function status(Request $request, KnowledgeBase $base): RedirectResponse
    {
        abort_unless($request->user()->can('knowledge.manage_bases'), 403);

        $validated = $request->validate([
            'status' => ['required', Rule::enum(KnowledgeBaseStatus::class)],
        ]);

        $status = KnowledgeBaseStatus::from($validated['status']);
        $before = ['status' => $base->status->value];

        $base->update([
            'status' => $status,
            'updated_by' => $request->user()->id,
            'approved_by' => $status === KnowledgeBaseStatus::Active ? $request->user()->id : $base->approved_by,
            'approved_at' => $status === KnowledgeBaseStatus::Active ? now() : $base->approved_at,
        ]);

        $this->audit->log('knowledge_base.status_changed', 'Situacao da base alterada.', $base, $before, ['status' => $status->value], $request->user());

        return back()->with('status', 'Situacao da base atualizada para '.$status->label().'.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'purpose' => ['nullable', 'string', 'max:1000'],
            'usage_policy' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    /**
     * @return array<int, array<string, int>>
     */
    private function flowPivot(Request $request): array
    {
        $ids = collect($request->input('flow_ids', []))
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique();

        return $ids->mapWithKeys(fn (int $id): array => [$id => ['priority' => 0]])->all();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'base';
        $slug = $base;
        $suffix = 1;

        while (KnowledgeBase::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }
}
