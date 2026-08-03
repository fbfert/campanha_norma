<?php

namespace App\Http\Controllers\Admin\ResponseGeneration;

use App\Enums\ReplySuggestionStatus;
use App\Enums\SuggestionFeedback;
use App\Http\Controllers\Controller;
use App\Models\ConversationReplySuggestion;
use App\Services\ResponseGeneration\ResponseModeResolver;
use App\Services\ResponseGeneration\SuggestionApprovalService;
use App\Services\ResponseGeneration\SuggestionSendGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReplySuggestionController extends Controller
{
    public function index(Request $request, ResponseModeResolver $modes): View
    {
        abort_unless($request->user()->can('reply_suggestions.view'), 403);

        $suggestions = ConversationReplySuggestion::query()
            ->with(['conversation.contact', 'sourceMessage', 'topic', 'approver'])
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')),
                fn ($query) => $query->where('status', ReplySuggestionStatus::Pending->value)
            )
            ->when($request->filled('flow_id'), fn ($query) => $query->where('conversation_flow_id', $request->integer('flow_id')))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.reply-suggestions.index', [
            'suggestions' => $suggestions,
            'statuses' => ReplySuggestionStatus::cases(),
            'globalMode' => $modes->global(),
            'canSeeContactData' => $request->user()->can('ai_insights.view_contact_data'),
        ]);
    }

    public function show(Request $request, ConversationReplySuggestion $suggestion, SuggestionSendGuard $guard): View
    {
        abort_unless($request->user()->can('reply_suggestions.view'), 403);

        $suggestion->load([
            'conversation.contact', 'sourceMessage', 'insight.topic', 'classification',
            'topic', 'state', 'flow', 'run', 'approver', 'rejecter', 'sentMessage',
            // Etapa 9D: as fontes que sustentaram a sugestão, validas e recusadas.
            'citations',
        ]);

        return view('admin.reply-suggestions.show', [
            'suggestion' => $suggestion,
            'stale' => $suggestion->isStale(),
            'sendCheck' => $guard->canSend($suggestion),
            'history' => ConversationReplySuggestion::query()
                ->where('source_message_id', $suggestion->source_message_id)
                ->orderByDesc('generation_attempt')
                ->get(),
            'feedbacks' => SuggestionFeedback::cases(),
            'canSeeContactData' => $request->user()->can('ai_insights.view_contact_data'),
        ]);
    }

    public function approve(Request $request, ConversationReplySuggestion $suggestion, SuggestionApprovalService $approvals): RedirectResponse
    {
        abort_unless($request->user()->can('reply_suggestions.approve'), 403);

        $validated = $request->validate([
            'final_text' => ['nullable', 'string', 'max:4096'],
        ]);

        $result = $approvals->approveAndSend($suggestion, $request->user(), $validated['final_text'] ?? null);

        return $result['sent']
            ? back()->with('success', 'Resposta aprovada e enfileirada para envio.')
            : back()->with('error', 'Não foi possível enviar: '.$result['reason']);
    }

    public function reject(Request $request, ConversationReplySuggestion $suggestion, SuggestionApprovalService $approvals): RedirectResponse
    {
        abort_unless($request->user()->can('reply_suggestions.reject'), 403);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $approvals->reject($suggestion, $request->user(), $validated['reason'] ?? null);

        return back()->with('success', 'Sugestão rejeitada.');
    }

    public function approveAll(Request $request, SuggestionApprovalService $approvals): RedirectResponse
    {
        abort_unless($request->user()->can('reply_suggestions.approve'), 403);

        $resultado = $approvals->approveAllPending($request->user());

        if ($resultado['enviadas'] === 0 && $resultado['recusadas'] === 0) {
            return back()->with('success', 'Nenhuma sugestão pendente valida para aprovar.');
        }

        $mensagem = "{$resultado['enviadas']} enviada(s).";

        // Os motivos aparecem na tela porque recusa em massa que não explica o
        // porquê deixa a pessoa achando que aprovou tudo.
        if ($resultado['recusadas'] > 0) {
            $detalhe = collect($resultado['motivos'])
                ->map(fn (int $total, string $motivo): string => "{$total} por {$motivo}")
                ->implode(', ');

            $mensagem .= " {$resultado['recusadas']} recusada(s): {$detalhe}.";
        }

        return back()->with('success', $mensagem);
    }

    public function discardStale(Request $request, SuggestionApprovalService $approvals): RedirectResponse
    {
        abort_unless($request->user()->can('reply_suggestions.reject'), 403);

        $total = $approvals->discardStale($request->user());

        return back()->with('success', $total === 0
            ? 'Nenhuma sugestão obsoleta na fila.'
            : "{$total} sugestão(ões) obsoleta(s) retirada(s) da fila. Nada foi enviado.");
    }

    public function regenerate(Request $request, ConversationReplySuggestion $suggestion, SuggestionApprovalService $approvals): RedirectResponse
    {
        abort_unless($request->user()->can('reply_suggestions.regenerate'), 403);

        $validated = $request->validate(['justification' => ['required', 'string', 'max:500']]);

        $new = $approvals->regenerate($suggestion, $request->user(), $validated['justification']);

        return $new
            ? redirect()->route('admin.reply-suggestions.show', $new)->with('success', 'Nova sugestão gerada.')
            : back()->with('error', 'Não foi possível regenerar esta sugestão.');
    }

    public function takeOver(Request $request, ConversationReplySuggestion $suggestion, SuggestionApprovalService $approvals): RedirectResponse
    {
        abort_unless($request->user()->can('reply_suggestions.reject'), 403);

        $approvals->takeOver($suggestion, $request->user());

        return back()->with('success', 'Conversa assumida manualmente. Automação pausada.');
    }

    public function feedback(Request $request, ConversationReplySuggestion $suggestion, SuggestionApprovalService $approvals): RedirectResponse
    {
        abort_unless($request->user()->can('reply_suggestions.feedback'), 403);

        $validated = $request->validate([
            'feedback' => ['required', 'string', 'in:'.implode(',', SuggestionFeedback::values())],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $approvals->feedback(
            $suggestion,
            $request->user(),
            SuggestionFeedback::from($validated['feedback']),
            $validated['reason'] ?? null,
        );

        return back()->with('success', 'Feedback registrado.');
    }
}
