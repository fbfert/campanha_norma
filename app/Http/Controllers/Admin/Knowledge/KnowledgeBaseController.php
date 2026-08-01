<?php

namespace App\Http\Controllers\Admin\Knowledge;

use App\Enums\KnowledgeBaseStatus;
use App\Enums\KnowledgeDocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\ConversationFlow;
use App\Models\KnowledgeBase;
use App\Services\AuditLogger;
use App\Services\Exports\SqlExportService;
use App\Services\Exports\TableExportService;
use App\Services\Exports\TableImportService;
use App\Services\Knowledge\KnowledgeBaseImporter;
use App\Services\Knowledge\KnowledgeGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Administração das bases de conhecimento.
 *
 * Ativar uma base e o ato que a torna alcancável pela busca. Por isso ele e
 * separado da edição e registrado em auditoria.
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

    /**
     * Exporta a relação de bases.
     *
     * Sai o que a tela já mostra — nome, situação, contagens — mais o que ajuda
     * a auditar: propósito, política de uso e quem aprovou. **Não sai nenhum
     * conteúdo de documento.** Uma planilha com o texto das bases seria uma
     * cópia do material oficial fora do controle de aprovação; para ler o
     * documento existe a tela dele, com quem aprovou registrado ao lado.
     */
    public function export(Request $request, TableExportService $export, SqlExportService $sql): BinaryFileResponse
    {
        abort_unless($request->user()->can('knowledge.view'), 403);

        $format = (string) $request->query('format', 'csv');

        if ($format === 'sql') {
            return $this->exportSql($sql);
        }

        $bases = KnowledgeBase::query()
            ->with('approver')
            // `flows` entra no `withCount` em vez de ser contado dentro do laço:
            // aqui se percorre a lista inteira, e uma consulta por base viraria
            // uma consulta por linha exportada.
            ->withCount([
                'documents',
                'documents as approved_documents_count' => fn ($query) => $query->where('status', KnowledgeDocumentStatus::Approved->value),
                'flows',
            ])
            ->orderBy('name')
            ->cursor()
            ->map(fn (KnowledgeBase $base): array => [
                $base->name,
                $base->slug,
                $base->description,
                $base->purpose,
                $base->usage_policy,
                $base->status->label(),
                $base->provider,
                $base->version,
                $base->documents_count,
                $base->approved_documents_count,
                $base->flows_count,
                $base->approver?->name,
                $base->approved_at?->format('d/m/Y H:i'),
                $base->created_at?->format('d/m/Y H:i'),
            ]);

        return $export->download(
            'bases-de-conhecimento',
            ['base', 'identificador', 'descricao', 'proposito', 'politica_de_uso', 'situacao', 'provedor', 'versao', 'documentos', 'documentos_aprovados', 'fluxos', 'aprovada_por', 'aprovada_em', 'criada_em'],
            $bases,
            $format,
            'knowledge_bases.exported',
            'Relação de bases de conhecimento exportada.',
        );
    }

    /**
     * A ficha das bases em `INSERT`.
     *
     * Recria a base vazia: nome, propósito, política de uso. **Nenhum documento
     * vai junto**, o que não e limitação e sim o ponto — documento oficial e
     * aprovado por uma pessoa dentro de um sistema, e não copiado por arquivo.
     *
     * Pelo mesmo motivo a situação sai forçada como rascunho e a versão como 1,
     * mesmo que a base de origem esteja ativa e na versão sete. Uma base que
     * chega por SQL não foi aprovada *aqui*; herdar o carimbo de aprovada seria
     * lavar a aprovação de um sistema para outro. Quem recebe reativa,
     * conscientemente.
     *
     * O `id` não sai: a base não faz referência a si mesma, então deixar o destino
     * numerar evita colisão sem perder nada.
     */
    private function exportSql(SqlExportService $sql): BinaryFileResponse
    {
        $columns = ['name', 'slug', 'description', 'purpose', 'usage_policy', 'status', 'version', 'provider', 'created_at', 'updated_at'];

        $rows = KnowledgeBase::query()
            ->orderBy('name')
            ->cursor()
            ->map(fn (KnowledgeBase $base): array => [
                $base->name,
                $base->slug,
                $base->description,
                $base->purpose,
                $base->usage_policy,
                KnowledgeBaseStatus::Draft->value,
                1,
                $base->provider,
                $base->created_at?->toDateTimeString(),
                $base->updated_at?->toDateTimeString(),
            ]);

        return $sql->download(
            'bases-de-conhecimento',
            'knowledge_bases',
            $columns,
            $rows,
            'knowledge_bases.exported',
            'Relação de bases de conhecimento exportada em SQL.',
            "Nenhum documento vai junto: documento oficial e aprovado por uma\n"
                ."pessoa dentro do sistema, não copiado por arquivo.\n"
                ."Por isso a situação sai como rascunho e a versão como 1, mesmo\n"
                ."que a base de origem esteja ativa. Quem recebe reativa e\n"
                .'reaprova conscientemente.',
        );
    }

    /**
     * Importação da ficha das bases, em duas fases: conferir, depois gravar.
     */
    public function import(Request $request): View
    {
        abort_unless($request->user()->can('knowledge.manage_bases'), 403);

        return view('admin.knowledge.bases.import', ['plan' => null, 'stored' => null]);
    }

    public function importPreview(Request $request, TableImportService $files, KnowledgeBaseImporter $importer): View
    {
        abort_unless($request->user()->can('knowledge.manage_bases'), 403);

        $request->validate(['file' => ['required', 'file', 'max:5120']]);

        $stored = $files->stash($request->file('file'));
        $plan = $importer->plan($files->read($stored));

        $request->session()->put('knowledge_bases.import', $stored);

        return view('admin.knowledge.bases.import', ['plan' => $plan, 'stored' => $stored]);
    }

    public function importConfirm(Request $request, TableImportService $files, KnowledgeBaseImporter $importer): RedirectResponse
    {
        abort_unless($request->user()->can('knowledge.manage_bases'), 403);

        $stored = (string) $request->session()->pull('knowledge_bases.import');

        if ($stored === '' || $stored !== $request->input('stored')) {
            return redirect()
                ->route('admin.knowledge.bases.import')
                ->withErrors(['file' => 'A conferência expirou. Envie o arquivo novamente.']);
        }

        $summary = $importer->apply($importer->plan($files->read($stored)), $request->user());
        $files->discard($stored);

        return redirect()->route('admin.knowledge.bases.index')->with(
            'status',
            "Importação concluída: {$summary['criadas']} criadas, {$summary['atualizadas']} atualizadas, {$summary['ignoradas']} ignoradas."
        );
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
            // Base nova nasce em rascunho, sempre: existir não e o mesmo que
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
     * Mudança de situação da base.
     *
     * Sai da edição de propósito: ativar uma base muda o que a IA pode afirmar,
     * e isso não pode ser efeito colateral de salvar um formulário.
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

        $this->audit->log('knowledge_base.status_changed', 'Situação da base alterada.', $base, $before, ['status' => $status->value], $request->user());

        return back()->with('status', 'Situação da base atualizada para '.$status->label().'.');
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
