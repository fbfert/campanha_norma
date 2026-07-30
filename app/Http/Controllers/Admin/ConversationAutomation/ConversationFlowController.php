<?php

namespace App\Http\Controllers\Admin\ConversationAutomation;

use App\Enums\ConversationFlowStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConversationAutomation\ConversationFlowRequest;
use App\Models\ConversationFlow;
use App\Models\MessageTemplate;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConversationFlowController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('conversation_automation.view'), 403);

        $flows = ConversationFlow::query()
            ->withCount(['questions', 'states'])
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.conversation-flows.index', ['flows' => $flows, 'statuses' => ConversationFlowStatus::cases()]);
    }

    public function create(Request $request): View
    {
        abort_unless($request->user()->can('conversation_automation.manage_flows'), 403);

        return view('admin.conversation-flows.create', $this->formData(new ConversationFlow));
    }

    public function store(ConversationFlowRequest $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();
        $data['transparency_enabled'] = $request->boolean('transparency_enabled');
        $data['created_by'] = $request->user()->id;

        $flow = ConversationFlow::create($data);
        $audit->log('conversation_flow.created', 'Fluxo conversacional criado.', $flow, null, ['name' => $flow->name], $request->user());

        return redirect()->route('admin.conversation-flows.show', $flow)->with('success', 'Fluxo criado com sucesso.');
    }

    public function show(Request $request, ConversationFlow $conversationFlow): View
    {
        abort_unless($request->user()->can('conversation_automation.view'), 403);

        return view('admin.conversation-flows.show', [
            'flow' => $conversationFlow->load(['questions.creator', 'creator', 'presentationTemplate']),
        ]);
    }

    public function edit(Request $request, ConversationFlow $conversationFlow): View
    {
        abort_unless($request->user()->can('conversation_automation.manage_flows'), 403);

        return view('admin.conversation-flows.edit', $this->formData($conversationFlow));
    }

    public function update(ConversationFlowRequest $request, ConversationFlow $conversationFlow, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();
        $data['transparency_enabled'] = $request->boolean('transparency_enabled');
        $data['updated_by'] = $request->user()->id;

        $old = $conversationFlow->only(['name', 'status']);
        $conversationFlow->update($data);
        $audit->log('conversation_flow.updated', 'Fluxo conversacional atualizado.', $conversationFlow, $old, $conversationFlow->only(['name', 'status']), $request->user());

        return redirect()->route('admin.conversation-flows.show', $conversationFlow)->with('success', 'Fluxo atualizado com sucesso.');
    }

    public function destroy(Request $request, ConversationFlow $conversationFlow, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('conversation_automation.manage_flows'), 403);

        $conversationFlow->delete();
        $audit->log('conversation_flow.deleted', 'Fluxo conversacional excluído logicamente.', $conversationFlow, null, null, $request->user());

        return redirect()->route('admin.conversation-flows.index')->with('success', 'Fluxo excluído logicamente.');
    }

    /** @return array<string, mixed> */
    private function formData(ConversationFlow $flow): array
    {
        return [
            'flow' => $flow,
            'statuses' => ConversationFlowStatus::cases(),
            'templates' => MessageTemplate::query()->orderBy('name')->limit(100)->get(),
        ];
    }
}
