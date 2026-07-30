<?php

namespace App\Http\Controllers\Admin\Analytics;

use App\Http\Controllers\Controller;
use App\Models\ConversationFlow;
use App\Services\Analytics\AiQualityMetricsService;
use App\Services\Analytics\DemandMetricsService;
use App\Services\Analytics\GeographyMetricsService;
use App\Services\Analytics\GovernanceReportService;
use App\Services\Analytics\ParticipationMetricsService;
use App\Services\Analytics\QuestionQualityMetricsService;
use App\Services\Analytics\SmallGroupSuppressor;
use App\Services\Analytics\TopicMetricsService;
use App\Services\SystemSettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Telas analiticas da subetapa 9E.
 *
 * Todo metodo exige `analytics.view_aggregates` como piso. Conteudo,
 * identificacao, custo e governanca sao permissoes adicionais conferidas
 * individualmente, e a ausencia de qualquer uma delas remove informacao da tela
 * sem impedir o acesso ao resto.
 */
class AnalyticsController extends Controller
{
    public function __construct(private readonly SystemSettingService $settings) {}

    public function dashboard(Request $request, ParticipationMetricsService $participation): View
    {
        $this->authorizeAggregates($request);
        [$from, $to] = $this->period($request);
        [$previousFrom, $previousTo] = $this->previousPeriod($from, $to);
        $flowId = $this->flowId($request);

        return view('admin.analytics.dashboard', [
            'overview' => $participation->overview($from, $to, $flowId),
            'comparison' => $participation->compare($from, $to, $previousFrom, $previousTo, $flowId),
            'byDay' => $participation->byDay($from, $to, $flowId),
        ] + $this->context($from, $to, $flowId));
    }

    public function topics(Request $request, TopicMetricsService $topics): View
    {
        $this->authorizeAggregates($request);
        [$from, $to] = $this->period($request);
        [$previousFrom, $previousTo] = $this->previousPeriod($from, $to);
        $flowId = $this->flowId($request);

        return view('admin.analytics.topics', [
            'mostMentioned' => $topics->mostMentioned($from, $to, $flowId),
            'emerging' => $topics->emerging($from, $to, $previousFrom, $previousTo, $flowId),
            'trend' => $topics->trend($from, $to, $flowId),
            'unclassified' => $topics->unclassified($from, $to, $flowId),
            'quality' => $topics->quality($from, $to, $flowId),
        ] + $this->context($from, $to, $flowId));
    }

    public function geography(Request $request, GeographyMetricsService $geography): View
    {
        $this->authorizeAggregates($request);
        [$from, $to] = $this->period($request);
        $flowId = $this->flowId($request);

        return view('admin.analytics.geography', [
            'declared' => $geography->byDeclaredLocality($from, $to, $flowId),
            'registered' => $geography->byRegisteredCity($from, $to, $flowId),
            'withoutLocality' => $geography->withoutLocality($from, $to, $flowId),
        ] + $this->context($from, $to, $flowId));
    }

    public function demands(Request $request, DemandMetricsService $demands): View
    {
        $this->authorizeAggregates($request);
        [$from, $to] = $this->period($request);
        $flowId = $this->flowId($request);
        $canSeeContent = $request->user()->can('analytics.view_content');

        // Problema, acao e resultado sao texto livre extraido do que a pessoa
        // escreveu. Agrupar esse texto e contar quantas vezes ele aparece nao o
        // transforma em agregado: o rotulo continua sendo a frase de alguem.
        // Por isso as tres tabelas exigem permissao de conteudo, e nao apenas
        // os exemplos. Quem tem so agregado ve urgencia e fila de revisao, que
        // sao categorias fechadas e nao carregam texto de ninguem.
        return view('admin.analytics.demands', [
            'problems' => $canSeeContent ? $demands->problems($from, $to, $flowId) : [],
            'actions' => $canSeeContent ? $demands->actions($from, $to, $flowId) : [],
            'results' => $canSeeContent ? $demands->results($from, $to, $flowId) : [],
            'byUrgency' => $demands->byUrgency($from, $to, $flowId),
            'reviewQueue' => $demands->reviewQueue($from, $to, $flowId),
            'examples' => $canSeeContent ? $demands->examples($from, $to, $flowId) : [],
            'canSeeContent' => $canSeeContent,
        ] + $this->context($from, $to, $flowId));
    }

    public function aiQuality(Request $request, AiQualityMetricsService $quality): View
    {
        $this->authorizeAggregates($request);
        [$from, $to] = $this->period($request);
        $flowId = $this->flowId($request);
        $canSeeCosts = $request->user()->can('analytics.view_costs');

        return view('admin.analytics.ai-quality', [
            'suggestions' => $quality->suggestions($from, $to, $flowId),
            'reasons' => $quality->reasons($from, $to, $flowId),
            'runs' => $quality->runs($from, $to, $canSeeCosts),
            'corrections' => $quality->classificationCorrections($from, $to, $flowId),
            'canSeeCosts' => $canSeeCosts,
        ] + $this->context($from, $to, $flowId));
    }

    public function questions(Request $request, QuestionQualityMetricsService $questions): View
    {
        $this->authorizeAggregates($request);
        [$from, $to] = $this->period($request);
        $flowId = $this->flowId($request);

        return view('admin.analytics.questions', [
            'byQuestion' => $questions->byQuestion($from, $to, $flowId),
            'permissionByFlow' => $questions->permissionByFlow($from, $to),
        ] + $this->context($from, $to, $flowId));
    }

    public function governance(Request $request, GovernanceReportService $governance): View
    {
        abort_unless($request->user()->can('analytics.view_governance'), 403);
        [$from, $to] = $this->period($request);

        return view('admin.analytics.governance', [
            'report' => $governance->report($from, $to),
        ] + $this->context($from, $to, null));
    }

    private function authorizeAggregates(Request $request): void
    {
        abort_unless($request->user()->can('analytics.view_aggregates'), 403);
    }

    /**
     * Contexto comum das telas: filtros aplicados, fluxos disponiveis e o
     * minimo de supressao, que aparece no rodape para que ninguem interprete
     * uma celula vazia como ausencia de resposta.
     *
     * @return array<string, mixed>
     */
    private function context(Carbon $from, Carbon $to, ?int $flowId): array
    {
        return [
            'from' => $from,
            'to' => $to,
            'flowId' => $flowId,
            'flows' => ConversationFlow::query()->orderBy('name')->get(['id', 'name']),
            'minimumCell' => app(SmallGroupSuppressor::class)->minimum(),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(Request $request): array
    {
        $days = (int) $this->settings->get('analytics.default_period_days', 30);

        $from = $request->filled('from')
            ? Carbon::parse($request->string('from')->toString())->startOfDay()
            : now()->subDays($days - 1)->startOfDay();

        $to = $request->filled('to')
            ? Carbon::parse($request->string('to')->toString())->endOfDay()
            : now()->endOfDay();

        return $from->lte($to) ? [$from, $to] : [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function previousPeriod(Carbon $from, Carbon $to): array
    {
        $days = max(1, $from->diffInDays($to) + 1);

        return [
            $from->copy()->subDays($days)->startOfDay(),
            $from->copy()->subDay()->endOfDay(),
        ];
    }

    private function flowId(Request $request): ?int
    {
        return $request->filled('flow') ? (int) $request->integer('flow') : null;
    }
}
