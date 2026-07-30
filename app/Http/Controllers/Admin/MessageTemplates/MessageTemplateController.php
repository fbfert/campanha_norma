<?php

namespace App\Http\Controllers\Admin\MessageTemplates;

use App\Enums\MessageTemplateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\MessageTemplates\MessageTemplateRequest;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Services\MessageTemplates\MessageTemplateService;
use App\Services\Placeholders\MessageRendererService;
use App\Services\Placeholders\PlaceholderCatalogService;
use App\Services\Placeholders\PlaceholderParserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MessageTemplateController extends Controller
{
    public function index(Request $request, PlaceholderParserService $parser): View
    {
        abort_unless($request->user()->can('message_templates.view'), 403);

        $templates = MessageTemplate::query()
            ->with('creator')
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('creator_id'), fn ($query) => $query->where('created_by', $request->integer('creator_id')))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.message-templates.index', compact('templates', 'parser'));
    }

    public function create(Request $request, PlaceholderCatalogService $catalog): View
    {
        abort_unless($request->user()->can('message_templates.create'), 403);

        return view('admin.message-templates.create', ['template' => new MessageTemplate, 'catalog' => $catalog->all(), 'statuses' => MessageTemplateStatus::cases()]);
    }

    public function store(MessageTemplateRequest $request, MessageTemplateService $service): RedirectResponse
    {
        $template = $service->create($request->validated(), $request->user());

        return redirect()->route('admin.message-templates.show', $template)->with('success', 'Modelo criado com sucesso.');
    }

    public function show(Request $request, MessageTemplate $messageTemplate, PlaceholderCatalogService $catalog): View
    {
        abort_unless($request->user()->can('message_templates.view'), 403);

        return view('admin.message-templates.show', ['template' => $messageTemplate->load('versions.creator', 'creator'), 'catalog' => $catalog->all(), 'contacts' => Contact::query()->orderBy('name')->limit(100)->get()]);
    }

    public function edit(Request $request, MessageTemplate $messageTemplate, PlaceholderCatalogService $catalog): View
    {
        abort_unless($request->user()->can('message_templates.update'), 403);

        return view('admin.message-templates.edit', ['template' => $messageTemplate, 'catalog' => $catalog->all(), 'statuses' => MessageTemplateStatus::cases()]);
    }

    public function update(MessageTemplateRequest $request, MessageTemplate $messageTemplate, MessageTemplateService $service): RedirectResponse
    {
        $service->update($messageTemplate, $request->validated(), $request->user());

        return redirect()->route('admin.message-templates.show', $messageTemplate)->with('success', 'Modelo atualizado com sucesso.');
    }

    public function duplicate(Request $request, MessageTemplate $messageTemplate, MessageTemplateService $service): RedirectResponse
    {
        abort_unless($request->user()->can('message_templates.duplicate'), 403);
        $copy = $service->duplicate($messageTemplate, $request->user());

        return redirect()->route('admin.message-templates.edit', $copy)->with('success', 'Modelo duplicado.');
    }

    public function status(Request $request, MessageTemplate $messageTemplate, MessageTemplateService $service): RedirectResponse
    {
        abort_unless($request->user()->can('message_templates.update'), 403);
        $data = $request->validate(['status' => ['required', Rule::enum(MessageTemplateStatus::class)]]);
        $service->setStatus($messageTemplate, MessageTemplateStatus::from($data['status']), $request->user());

        return back()->with('success', 'Status alterado.');
    }

    public function destroy(Request $request, MessageTemplate $messageTemplate, MessageTemplateService $service): RedirectResponse
    {
        abort_unless($request->user()->can('message_templates.delete'), 403);
        $service->delete($messageTemplate, $request->user());

        return redirect()->route('admin.message-templates.index')->with('success', 'Modelo excluído logicamente.');
    }

    public function restore(Request $request, int $messageTemplate, MessageTemplateService $service): RedirectResponse
    {
        abort_unless($request->user()->can('message_templates.restore'), 403);
        $template = MessageTemplate::withTrashed()->findOrFail($messageTemplate);
        $service->restore($template, $request->user());

        return redirect()->route('admin.message-templates.show', $template)->with('success', 'Modelo restaurado.');
    }

    public function preview(Request $request, MessageRendererService $renderer): View
    {
        abort_unless($request->user()->can('message_templates.view'), 403);
        $data = $request->validate(['body' => ['required', 'string'], 'contact_id' => ['required', 'exists:contacts,id']]);
        $contact = Contact::findOrFail($data['contact_id']);

        return view('admin.message-templates.preview', ['contact' => $contact, 'result' => $renderer->render($data['body'], $contact)]);
    }
}
