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
 * Administração dos documentos de uma base.
 *
 * Aprovar e o único ato que torna um documento alcancável pela busca, e ele não
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
        ], [
            // Sem estas duas, o PHP recusando o envio produzia "Falha ao enviar
            // o arquivo file." — que nomeia o campo pelo nome interno e não diz
            // o que fazer. Quem lia isso não tinha como saber que era tamanho.
            'file.uploaded' => 'O arquivo não chegou inteiro ao servidor. Quase sempre e tamanho: o limite atual e de '
                .$this->limiteEmMb().' MB.',
            'file.max' => 'O arquivo passa do limite de '.$this->limiteEmMb().' MB.',
            'file.required' => 'Escolha um arquivo para enviar.',
        ], [
            'file' => 'arquivo',
        ]);

        try {
            $document = $this->ingestion->store($base, $request->file('file'), $data, $request->user());
        } catch (KnowledgeProviderException $exception) {
            // Código de erro, nunca a mensagem do provedor: ela pode carregar
            // caminho de arquivo ou trecho de requisição.
            return back()->withInput()->withErrors(['file' => 'Falha no envio: '.$exception->errorCode.'.']);
        }

        $redirect = redirect()
            ->route('admin.knowledge.documents.show', [$base, $document])
            ->with('status', 'Documento enviado. A indexação acontece em segundo plano.');

        // Mesmo arquivo em outra base não impede o envio, mas precisa ser dito:
        // com o texto repetido, a busca devolve o mesmo trecho duas vezes e a
        // resposta sai com citação em duplicata. Quem envia olhando a tela de
        // uma base não tem como perceber isso sozinho.
        $repetidos = $this->ingestion->duplicatesInOtherBases($document);

        if ($repetidos->isNotEmpty()) {
            $onde = $repetidos->map(fn ($outro) => '"'.$outro->base?->name.'"')->unique()->join(', ', ' e ');

            $redirect->with('error', 'Atenção: este mesmo arquivo já está na base '.$onde
                .'. Manter a mesma cópia em bases diferentes faz a busca devolver o conteúdo repetido.');
        }

        return $redirect;
    }

    /** O limite efetivo, em megabytes, para escrever nas mensagens. */
    private function limiteEmMb(): int
    {
        return max(1, intdiv($this->ingestion->maxFileSizeKb(), 1024));
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
            // O aviso precisa continuar visível depois do envio, e não só na
            // mensagem que some ao recarregar a página.
            'repetidos' => $this->ingestion->duplicatesInOtherBases($document),
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

        return back()->with('status', 'Documento aprovado e disponível para busca.');
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

        return back()->with('status', 'Documento marcado como obsoleto. O histórico das respostas que ele sustentou continua intacto.');
    }

    public function reprocess(Request $request, KnowledgeBase $base, KnowledgeDocument $document, SystemSettingService $settings): RedirectResponse
    {
        abort_unless($request->user()->can('knowledge.upload_documents'), 403);
        abort_unless($document->knowledge_base_id === $base->id, 404);

        if (! $document->status->canBeReprocessed()) {
            return back()->withErrors(['status' => 'Este documento não pode ser reprocessado na situação atual.']);
        }

        $document->update(['status' => KnowledgeDocumentStatus::Processing, 'error_message' => null]);

        IndexKnowledgeDocumentJob::dispatch($document->id)
            ->onQueue((string) $settings->get('knowledge.queue', 'knowledge-indexing'));

        $this->audit->log('knowledge_document.reprocess_requested', 'Reprocessamento solicitado.', $document, null, null, $request->user());

        return back()->with('status', 'Reprocessamento enfileirado. A aprovação anterior será revogada.');
    }

    public function destroy(Request $request, KnowledgeBase $base, KnowledgeDocument $document): RedirectResponse
    {
        abort_unless($request->user()->can('knowledge.delete_documents'), 403);
        abort_unless($document->knowledge_base_id === $base->id, 404);

        $this->indexing->delete($document, $request->user());

        return redirect()
            ->route('admin.knowledge.bases.show', $base)
            ->with('status', 'Documento excluído. As citações já registradas continuam existindo com o conteúdo que sustentou cada resposta.');
    }

    /**
     * Download do arquivo original.
     *
     * O caminho vem do banco, nunca da requisição, e o disco e privado. Não ha
     * parâmetro de caminho para travessia acontecer.
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
