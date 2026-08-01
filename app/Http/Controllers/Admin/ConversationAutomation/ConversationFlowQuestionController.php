<?php

namespace App\Http\Controllers\Admin\ConversationAutomation;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConversationAutomation\ConversationFlowQuestionRequest;
use App\Models\ConversationFlow;
use App\Models\ConversationFlowQuestion;
use App\Services\AuditLogger;
use App\Services\Placeholders\PlaceholderCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConversationFlowQuestionController extends Controller
{
    public function create(Request $request, ConversationFlow $conversationFlow): View
    {
        abort_unless($request->user()->can('conversation_automation.manage_questions'), 403);

        return view('admin.conversation-flows.questions.create', [
            'flow' => $conversationFlow,
            'question' => new ConversationFlowQuestion,
            'catalog' => app(PlaceholderCatalogService::class)->all(),
        ]);
    }

    public function store(ConversationFlowQuestionRequest $request, ConversationFlow $conversationFlow, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');
        $data['conversation_flow_id'] = $conversationFlow->id;
        $data['created_by'] = $request->user()->id;

        $question = ConversationFlowQuestion::create($data);
        $audit->log('conversation_flow_question.created', 'Pergunta criada.', $question, null, ['flow_id' => $conversationFlow->id], $request->user());

        return redirect()->route('admin.conversation-flows.show', $conversationFlow)->with('success', 'Pergunta criada com sucesso.');
    }

    public function edit(Request $request, ConversationFlow $conversationFlow, ConversationFlowQuestion $question): View
    {
        abort_unless($request->user()->can('conversation_automation.manage_questions'), 403);
        abort_unless($question->conversation_flow_id === $conversationFlow->id, 404);

        return view('admin.conversation-flows.questions.edit', [
            'flow' => $conversationFlow,
            'question' => $question,
            'catalog' => app(PlaceholderCatalogService::class)->all(),
        ]);
    }

    public function update(ConversationFlowQuestionRequest $request, ConversationFlow $conversationFlow, ConversationFlowQuestion $question, AuditLogger $audit): RedirectResponse
    {
        abort_unless($question->conversation_flow_id === $conversationFlow->id, 404);

        $data = $request->validated();
        $data['is_active'] = $request->boolean('is_active');
        $data['updated_by'] = $request->user()->id;
        // Alterar o texto gera nova versão; snapshots já enviados permanecem intactos.
        $data['version'] = $data['text'] !== $question->text ? $question->version + 1 : $question->version;

        $old = $question->only(['internal_title', 'text', 'is_active', 'weight']);
        $question->update($data);
        $audit->log('conversation_flow_question.updated', 'Pergunta atualizada.', $question, $old, $question->only(['internal_title', 'text', 'is_active', 'weight']), $request->user());

        return redirect()->route('admin.conversation-flows.show', $conversationFlow)->with('success', 'Pergunta atualizada com sucesso.');
    }

    public function destroy(Request $request, ConversationFlow $conversationFlow, ConversationFlowQuestion $question, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('conversation_automation.manage_questions'), 403);
        abort_unless($question->conversation_flow_id === $conversationFlow->id, 404);

        // Exclusão apenas lógica: perguntas já utilizadas preservam o histórico.
        $question->delete();
        $audit->log('conversation_flow_question.deleted', 'Pergunta excluída logicamente.', $question, null, null, $request->user());

        return redirect()->route('admin.conversation-flows.show', $conversationFlow)->with('success', 'Pergunta excluída logicamente.');
    }
}
