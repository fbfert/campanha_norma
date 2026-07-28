<?php

namespace App\Http\Controllers\Admin\MessageBatches;

use App\Enums\MessageBatchSelectionType;
use App\Enums\MessageBatchStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\MessageBatches\MessageBatchRequest;
use App\Models\MessageBatch;
use App\Models\MessageTemplate;
use App\Services\MessageBatches\BatchCreationService;
use App\Services\MessageBatches\BatchPreviewExportService;
use App\Services\Placeholders\PlaceholderCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MessageBatchController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('message_batches.view'), 403);

        $batches = MessageBatch::query()
            ->with('template', 'creator')
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('is_campaign'), fn ($query) => $query->where('is_campaign', $request->boolean('is_campaign')))
            ->when($request->filled('message_template_id'), fn ($query) => $query->where('message_template_id', $request->integer('message_template_id')))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.message-batches.index', ['batches' => $batches, 'templates' => MessageTemplate::orderBy('name')->get(), 'statuses' => MessageBatchStatus::cases()]);
    }

    public function create(Request $request, PlaceholderCatalogService $catalog): View
    {
        abort_unless($request->user()->can('message_batches.create'), 403);

        return view('admin.message-batches.create', $this->formData($catalog));
    }

    public function createCampaign(Request $request, PlaceholderCatalogService $catalog): View
    {
        abort_unless($request->user()->can('message_batches.create'), 403);

        return view('admin.message-batches.create', array_merge($this->formData($catalog), ['campaignMode' => true]));
    }

    public function store(MessageBatchRequest $request, BatchCreationService $service): RedirectResponse
    {
        $batch = $service->create($request->validated(), $request->user());

        return redirect()->route('admin.message-batches.show', $batch)->with('success', 'Lote criado como rascunho.');
    }

    public function show(Request $request, MessageBatch $messageBatch): View
    {
        abort_unless($request->user()->can('message_batches.view'), 403);

        return view('admin.message-batches.show', [
            'batch' => $messageBatch->load(['template', 'creator', 'events.user']),
            'recipients' => $messageBatch->recipients()->paginate(20),
        ]);
    }

    public function edit(Request $request, MessageBatch $messageBatch, PlaceholderCatalogService $catalog): View
    {
        abort_unless($request->user()->can('message_batches.update'), 403);
        abort_if($messageBatch->status !== MessageBatchStatus::Draft, 403, 'Lotes preparados ou cancelados nao podem ser editados diretamente.');

        return view('admin.message-batches.edit', array_merge($this->formData($catalog), ['batch' => $messageBatch]));
    }

    public function update(MessageBatchRequest $request, MessageBatch $messageBatch, BatchCreationService $service): RedirectResponse
    {
        $service->update($messageBatch, $request->validated(), $request->user());

        return redirect()->route('admin.message-batches.show', $messageBatch)->with('success', 'Lote atualizado.');
    }

    public function validateBatch(Request $request, MessageBatch $messageBatch): RedirectResponse
    {
        abort_unless($request->user()->can('message_batches.update'), 403);

        return back()->with('success', "Validacao concluida: {$messageBatch->eligible_total} aptos e {$messageBatch->ineligible_total} excluidos.");
    }

    public function randomize(Request $request, MessageBatch $messageBatch, BatchCreationService $service): RedirectResponse
    {
        abort_unless($request->user()->can('message_batches.update'), 403);
        $service->randomize($messageBatch, $request->user());

        return back()->with('success', 'Ordem aleatoria regerada.');
    }

    public function prepare(Request $request, MessageBatch $messageBatch, BatchCreationService $service): RedirectResponse
    {
        abort_unless($request->user()->can('message_batches.update'), 403);
        $data = $request->validate(['confirmation' => ['required', 'string']]);
        $service->prepare($messageBatch, $request->user(), $data['confirmation']);

        return back()->with('success', 'Lote preparado. O processamento automatico ainda nao foi implementado.');
    }

    public function duplicate(Request $request, MessageBatch $messageBatch, BatchCreationService $service): RedirectResponse
    {
        abort_unless($request->user()->can('message_batches.duplicate'), 403);
        $copy = $service->duplicate($messageBatch, $request->user());

        return redirect()->route('admin.message-batches.edit', $copy)->with('success', 'Lote duplicado como rascunho.');
    }

    public function cancel(Request $request, MessageBatch $messageBatch, BatchCreationService $service): RedirectResponse
    {
        abort_unless($request->user()->can('message_batches.cancel'), 403);
        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:1000']]);
        $service->cancel($messageBatch, $request->user(), $data['cancel_reason']);

        return back()->with('success', 'Lote cancelado.');
    }

    public function destroy(Request $request, MessageBatch $messageBatch): RedirectResponse
    {
        abort_unless($request->user()->can('message_batches.update'), 403);
        abort_if($messageBatch->status !== MessageBatchStatus::Draft || $messageBatch->prepared_at, 403);
        $messageBatch->delete();

        return redirect()->route('admin.message-batches.index')->with('success', 'Rascunho excluido.');
    }

    public function recipients(Request $request, MessageBatch $messageBatch): View
    {
        abort_unless($request->user()->can('message_batches.view_recipients'), 403);

        $recipients = $messageBatch->recipients()
            ->when($request->filled('eligibility_status'), fn ($query) => $query->where('eligibility_status', $request->string('eligibility_status')))
            ->when($request->filled('q'), fn ($query) => $query->where('contact_name_snapshot', 'like', '%'.$request->string('q').'%'))
            ->paginate(20)
            ->withQueryString();

        return view('admin.message-batches.recipients', compact('messageBatch', 'recipients'));
    }

    public function exportPreview(Request $request, MessageBatch $messageBatch, BatchPreviewExportService $export): BinaryFileResponse
    {
        abort_unless($request->user()->can('message_batches.export_preview'), 403);

        return $export->export($messageBatch, $request->input('format', 'csv'));
    }

    private function formData(PlaceholderCatalogService $catalog): array
    {
        return [
            'templates' => MessageTemplate::query()->where('status', 'active')->orderBy('name')->get(),
            'catalog' => $catalog->all(),
            'selectionTypes' => MessageBatchSelectionType::cases(),
        ];
    }
}
