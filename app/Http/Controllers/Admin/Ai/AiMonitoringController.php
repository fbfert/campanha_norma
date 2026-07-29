<?php

namespace App\Http\Controllers\Admin\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiRun;
use App\Services\Ai\AiCircuitBreaker;
use App\Services\Ai\AiMonitoringService;
use App\Services\Ai\AiProviderManager;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AiMonitoringController extends Controller
{
    public function index(
        Request $request,
        AiMonitoringService $monitoring,
        AiProviderManager $providers,
        AiCircuitBreaker $circuit
    ): View {
        abort_unless($request->user()->can('ai_insights.view_monitoring'), 403);

        $days = max(1, min(90, $request->integer('days', 7)));
        $provider = $providers->provider();

        return view('admin.ai-monitoring.index', [
            'days' => $days,
            'metrics' => $monitoring->metrics($days),
            'stuck' => $monitoring->stuck()->latest('started_at')->limit(20)->get(),
            'recent' => AiRun::query()->with('conversation')->latest('id')->limit(20)->get(),
            'provider' => $provider->name(),
            'model' => $provider->model(),
            'circuitOpen' => $circuit->isOpen($provider->name()),
            'circuitFailures' => $circuit->failures($provider->name()),
        ]);
    }
}
