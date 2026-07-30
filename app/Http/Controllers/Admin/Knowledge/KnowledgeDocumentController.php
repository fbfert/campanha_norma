<?php

namespace App\Http\Controllers\Admin\Knowledge;

use App\Enums\KnowledgeDocumentStatus;
use App\Enums\KnowledgeDocumentType;
use App\Exceptions\Knowledge\KnowledgeProviderException;
use App\Http\Controllers\Controller;
use App\Jobs\IndexKnowledgeDocumentJob;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Services\AuditLogger;
use App\Services\Knowledge\DocumentIngestionService;
use App\Services\Knowledge\KnowledgeIndexingService;
use App\Services\SystemSettingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Administracao dos documentos de uma base.
 *
 * Aprovar e o unico ato que torna um documento alcancavel pela busca, e ele nao
 * pode ser encadeado com o envio: quem aprova declara ter lido o que aprovou.
 */
class KnowledgeDocumentController extends Controller
{
    public function __construct(
        private readonly DocumentIngestionService $ingestion,
        private readonly KnowledgeIndexingService $indexing,
        private readonly AuditLogger $audit,
    ) {}

    public function create(Request $request, KnowledgeBase $base): View
    {
        abort_unless($request->user()->can('knowledge.upload_documents'), 403);

        return view('admin.knowledge.documents.create', [
            'base' => $base,
            'types' => KnowledgeDocumentType::cases(),
            'acceptedMimeTypes' => $this->ingestion->acceptedMimeTypes(),
            'maxFileSizeKb' => $this->ingestion->maxFileSizeKb(),
            'candidates' => $base->documents()
                ->whereIn('status', [KnowledgeDocumentStatus::Approved->value, KnowledgeDocumentStatus::Ready->value])
                ->orderBy('title')
                ->get(),
        ]);
    }

    public function store(Request $request, KnowledgeBase $base): RedirectResponse
    {
        abort_unless($request->user()->can('knowledge.upload_documents'), 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(KnowledgeDocumentType::class)],
            'source' => ['nullable', 'string', 'max:255'],
            'source_url' => ['nullable', 'url', 'max:2048'],
            'document_date' => ['nullable', 'date'],
            'supersedes_document_id' => ['nullable', Rule::exists('knowledge_documents', 'id')->where('knowledge_base_id', $base->id)],
            'version' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'file' => ['required', 'file', 'max:'.$this->ingestion->maxFileSizeKb()],
        ]);

        try {
            $document = $this->ingestion->store($base, $request->file('file'), $data, $request->user());
        } catch (KnowledgeProviderException $exception) {
            // Codigo de erro, nunca a mensagem do provedor: ela pode carregar
            // caminho de arquivo ou trecho de requisicao.
            return back()->withInput()->withErrors(['file' => 'Falha no envio: '.$exception->errorCode.'.']);
        }

        return redirect()
            ->route('admin.knowledge.documents.show', [$base, $document])
            ->with('status', 'Documento enviado. A indexacao acontece em segundo plano.');
    }

    public function show(Request $request, KnowledgeBase $base, KnowledgeDocument $document): View
    {
        abort_unless($request->user()->can('knowledge.view'), 403);
        abort_unless($document->knowledge_base_id === $base->id, 404);

        return view('admin.knowledge.documents.show', [
            'base' => $base,
            'document' => $document->load(['creator', 'approver', 'supersedes']),
            'chunks' => $document->chunks()->orderBy('chunk_index')->paginate(10),
            'extractPreview' => mb_substr((string) $document->extracted_text, 0, 5000),
        ]);
    }

    public function approve(Request $request, KnowledgeBase $base, KnowledgeDocument $document): RedirectResponse
    {
        abort_unless($request->user()->can('knowledge.approve_documents'), 403);
        abort_unless($document->knowledge_base_id === $base->id, 404);

        if (! $document->status->canBeApproved()) {
            return back()->withErrors(['status' => 'Somente documento indexado com sucesso pode ser aprovado.']);
        }

        $this->indexing->approve($document, $request->user());

        return back()->with('status', 'Documento aprovado e disponivel para busca.');
    }

    public function reject(Request $request, KnowledgeBase $base, KnowledgeDocument $document): RedirectResponse
    {
        abort_unless($request->user()->can('knowledge.approve_documents'), 403);
        abort_unless($document->knowledge_base_id === $base->id, 404);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $this->indexing->reject($document, $request->user(), $data['reason'] ?? null);

        return back()->with('status', 'Documento rejeitado.');
    }

    public function obsolete(Request $request, KnowledgeBase $base, KnowledgeDocument $document): RedirectResponse
    {
        abort_unless($request->user()->can('knowledge.approve_documents'), 403);
        abort_unless($document->knowledge_base_id === $base->id, 404);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        $this->indexing->obsolete($document, $request->user(), $data['reason'] ?? null);

        return back()->with('status', 'Documento marcado como obsoleto. O historico das respostas que ele sustentou continua intacto.');
    }

    public function reprocess(Request $request, KnowledgeBase $base, KnowledgeDocument $document, SystemSettingService $settings): RedirectResponse
    {
        abort_unless($request->user()->can('knowledge.upload_documents'), 403);
        abort_unless($document->knowledge_base_id === $base->id, 404);

        if (! $document->status->canBeReprocessed()) {
            return back()->withErrors(['status' => 'Este documento nao pode ser reprocessado na situacao atual.']);
        }

        $document->update(['status' => KnowledgeDocumentStatus::Processing, 'error_message' => null]);

        IndexKnowledgeDocumentJob::dispatch($document->id)
            ->onQueue((string) $settings->get('knowledge.queue', 'knowledge-indexing'));

        $this->audit->log('knowledge_document.reprocess_requested', 'Reprocessamento solicitado.', $document, null, null, $request->user());

        return back()->with('status', 'Reprocessamento enfileirado. A aprovacao anterior sera revogada.');
    }

    public function destroy(Request $request, KnowledgeBase $base, KnowledgeDocument $document): RedirectResponse
    {
        abort_unless($request->user()->can('knowledge.delete_documents'), 403);
        abort_unless($document->knowledge_base_id === $base->id, 404);

        $this->indexing->delete($document, $request->user());

        return redirect()
            ->route('admin.knowledge.bases.show', $base)
            ->with('status', 'Documento excluido. As citacoes ja registradas continuam existindo com o conteudo que sustentou cada resposta.');
    }

    /**
     * Download do arquivo original.
     *
     * O caminho vem do banco, nunca da requisicao, e o disco e privado. Nao ha
     * parametro de caminho para travessia acontecer.
     */
    public function download(Request $request, KnowledgeBase $base, KnowledgeDocument $document): StreamedResponse
    {
        abort_unless($request->user()->can('knowledge.download_documents'), 403);
        abort_unless($document->knowledge_base_id === $base->id, 404);

        $disk = Storage::disk($document->disk);
        abort_unless($disk->exists($document->file_path), 404);

        $this->audit->log('knowledge_document.downloaded', 'Arquivo original baixado.', $document, null, null, $request->user());

        return $disk->download($document->file_path, $document->original_filename ?: 'documento');
    }
}
