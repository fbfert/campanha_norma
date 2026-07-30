<?php

namespace App\Http\Controllers\Admin\ConversationAutomation;

use App\Enums\ConversationFlowStage;
use App\Http\Controllers\Controller;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowState;
use App\Services\ConversationAutomation\ConversationFlowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConversationFlowStateController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('conversation_automation.view'), 403);

        $states = ConversationFlowState::query()
            ->with(['conversation.contact', 'flow'])
            ->when($request->filled('stage'), fn ($query) => $query->where('current_stage', $request->string('stage')))
            ->when($request->filled('flow_id'), fn ($query) => $query->where('conversation_flow_id', $request->integer('flow_id')))
            ->when($request->boolean('needs_human'), fn ($query) => $query->where('needs_human_review', true))
            ->when($request->boolean('paused'), fn ($query) => $query->where('is_paused', true))
            ->latest('last_transition_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.conversation-automation.index', [
            'states' => $states,
            'stages' => ConversationFlowStage::cases(),
            'flows' => ConversationFlow::query()->orderBy('name')->get(),
        ]);
    }

    public function show(Request $request, ConversationFlowState $state): View
    {
        abort_unless($request->user()->can('conversation_automation.view'), 403);

        return view('admin.conversation-automation.show', [
            'state' => $state->load(['conversation.contact', 'flow', 'selectedQuestion', 'transitions.user', 'questionUsages.question']),
        ]);
    }

    public function pause(Request $request, ConversationFlowState $state, ConversationFlowService $flows): RedirectResponse
    {
        abort_unless($request->user()->can('conversation_automation.control'), 403);
        $flows->pause($state, $request->user());

        return back()->with('success', 'Automação pausada.');
    }

    public function resume(Request $request, ConversationFlowState $state, ConversationFlowService $flows): RedirectResponse
    {
        abort_unless($request->user()->can('conversation_automation.control'), 403);
        $flows->resume($state, $request->user());

        return back()->with('success', 'Automação retomada.');
    }

    public function finish(Request $request, ConversationFlowState $state, ConversationFlowService $flows): RedirectResponse
    {
        abort_unless($request->user()->can('conversation_automation.control'), 403);
        $flows->finishManually($state, $request->user());

        return back()->with('success', 'Automação encerrada.');
    }

    public function takeOver(Request $request, ConversationFlowState $state, ConversationFlowService $flows): RedirectResponse
    {
        abort_unless($request->user()->can('conversation_automation.control'), 403);
        $flows->takeOver($state, $request->user());

        return back()->with('success', 'Conversa assumida manualmente.');
    }
}
