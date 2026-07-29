<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Enums\InsightReviewReason;
use App\Enums\InsightSentiment;
use App\Enums\InsightUrgency;
use App\Enums\MessageClassification;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\ConversationInsightCorrectionRequest;
use App\Jobs\InterpretConversationMessageJob;
use App\Models\ConversationInsight;
use App\Models\ConversationMessageClassification;
use App\Models\InsightTopic;
use App\Services\Ai\InsightCorrectionService;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConversationInsightController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->can('ai_insights.view'), 403);

        $insights = ConversationInsight::query()
            ->with(['topic', 'conversation.contact', 'sourceMessage'])
            ->when($request->boolean('needs_review'), fn ($query) => $query->where('requires_human_review', true)->where('reviewed', false))
            ->when($request->filled('topic_id'), fn ($query) => $query->where('insight_topic_id', $request->integer('topic_id')))
            ->when($request->filled('urgency'), fn ($query) => $query->where('urgency', $request->string('urgency')))
            ->when($request->filled('sentiment'), fn ($query) => $query->where('sentiment', $request->string('sentiment')))
            ->when($request->filled('reason'), fn ($query) => $query->where('review_reason', $request->string('reason')))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.ai-insights.index', [
            'insights' => $insights,
            'topics' => InsightTopic::query()->orderBy('display_order')->get(),
            'urgencies' => InsightUrgency::cases(),
            'sentiments' => InsightSentiment::cases(),
            'reasons' => InsightReviewReason::cases(),
            'canSeeContactData' => $request->user()->can('ai_insights.view_contact_data'),
        ]);
    }

    public function show(Request $request, ConversationInsight $insight): View
    {
        abort_unless($request->user()->can('ai_insights.view'), 403);

        return view('admin.ai-insights.show', [
            'insight' => $insight->load([
                'topic', 'conversation.contact', 'sourceMessage', 'run',
                'topicLinks.topic', 'corrections.user', 'reviewer', 'question',
            ]),
            'classification' => ConversationMessageClassification::query()
                ->with('run')
                ->where('conversation_message_id', $insight->source_message_id)
                ->latest('id')
                ->first(),
            'versions' => ConversationInsight::query()
                ->where('source_message_id', $insight->source_message_id)
                ->orderByDesc('extraction_version')
                ->get(),
            'topics' => InsightTopic::query()->where('is_active', true)->orderBy('display_order')->get(),
            'urgencies' => InsightUrgency::cases(),
            'sentiments' => InsightSentiment::cases(),
            'classifications' => MessageClassification::cases(),
            'canSeeContactData' => $request->user()->can('ai_insights.view_contact_data'),
        ]);
    }

    public function correct(
        ConversationInsightCorrectionRequest $request,
        ConversationInsight $insight,
        InsightCorrectionService $corrections
    ): RedirectResponse {
        $changed = $corrections->correctInsight(
            $insight,
            $request->insightValues(),
            $request->user(),
            $request->input('reason')
        );

        if ($request->filled('classification')) {
            $classification = ConversationMessageClassification::query()
                ->where('conversation_message_id', $insight->source_message_id)
                ->latest('id')
                ->first();

            if ($classification) {
                $corrections->correctClassification(
                    $classification,
                    MessageClassification::from($request->string('classification')->toString()),
                    $request->user(),
                    $request->input('reason')
                );
            }
        }

        return back()->with('success', $changed > 0
            ? 'Correcao registrada.'
            : 'Nenhum campo do insight foi alterado.');
    }

    public function approve(Request $request, ConversationInsight $insight, InsightCorrectionService $corrections): RedirectResponse
    {
        abort_unless($request->user()->can('ai_insights.correct'), 403);

        $corrections->approve($insight, $request->user());

        return back()->with('success', 'Insight marcado como revisado.');
    }

    public function reprocess(Request $request, ConversationInsight $insight, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('ai_insights.reprocess'), 403);

        InterpretConversationMessageJob::dispatch($insight->source_message_id);

        $audit->log('ai_insights.reprocess_requested', 'Reprocessamento de interpretacao solicitado.', $insight, null, [
            'insight_id' => $insight->id,
            'source_message_id' => $insight->source_message_id,
        ], $request->user());

        return back()->with('success', 'Reprocessamento enfileirado.');
    }
}
