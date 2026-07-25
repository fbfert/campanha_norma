<?php

namespace App\Http\Controllers\Admin\Monitoring;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\Monitoring\MonitoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MonitoringController extends Controller
{
    public function index(Request $request, MonitoringService $monitoring, AuditLogger $audit): View
    {
        abort_unless($request->user()->can('monitoring.view'), 403);
        $audit->log('monitoring.viewed', 'Monitoramento operacional visualizado.');

        return view('admin.monitoring.index', ['items' => $monitoring->diagnostics()]);
    }

    public function run(Request $request, MonitoringService $monitoring, AuditLogger $audit): RedirectResponse
    {
        abort_unless($request->user()->can('monitoring.run_diagnostics'), 403);
        $monitoring->diagnostics();
        $audit->log('monitoring.diagnostic_run', 'Diagnostico executado pelo painel.', null, null, ['manual' => true], $request->user());

        return back()->with('success', 'Diagnostico executado.');
    }

    public function failedJobs(Request $request): View
    {
        abort_unless($request->user()->can('monitoring.view'), 403);

        return view('admin.monitoring.failed-jobs', ['jobs' => DB::table('failed_jobs')->latest('failed_at')->paginate(25)]);
    }

    public function deleteFailedJob(Request $request, string $job): RedirectResponse
    {
        abort_unless($request->user()->can('maintenance.run_commands'), 403);
        DB::table('failed_jobs')->where('uuid', $job)->delete();

        return back()->with('success', 'Job falho removido.');
    }
}
