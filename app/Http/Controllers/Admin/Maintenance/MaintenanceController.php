<?php

namespace App\Http\Controllers\Admin\Maintenance;

use App\Http\Controllers\Controller;
use App\Services\Maintenance\MaintenanceService;
use App\Services\Monitoring\MonitoringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MaintenanceController extends Controller
{
    public function index(Request $request, MonitoringService $monitoring): View
    {
        abort_unless($request->user()->can('maintenance.view'), 403);

        return view('admin.maintenance.index', ['diagnostics' => $monitoring->diagnostics()]);
    }

    public function syncCounters(Request $request, MaintenanceService $maintenance): RedirectResponse
    {
        abort_unless($request->user()->can('maintenance.sync_counters'), 403);
        $request->validate(['confirm' => ['accepted']]);
        $count = $maintenance->syncCounters();

        return back()->with('success', "Contadores sincronizados: {$count}.");
    }

    public function findInconsistencies(Request $request, MonitoringService $monitoring): RedirectResponse
    {
        abort_unless($request->user()->can('maintenance.view'), 403);
        $count = $monitoring->inconsistentBatches()['details']['count'] ?? 0;

        return back()->with('success', "Lotes inconsistentes encontrados: {$count}.");
    }

    public function recoverStuck(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('maintenance.recover_stuck'), 403);
        $request->validate(['confirm' => ['accepted']]);
        \Artisan::call('messages:recover-stuck');

        return back()->with('success', trim(\Artisan::output()) ?: 'Recuperação executada.');
    }

    public function cleanup(Request $request, MaintenanceService $maintenance): RedirectResponse
    {
        abort_unless($request->user()->can('maintenance.cleanup_logs'), 403);
        $request->validate(['confirm' => ['accepted']]);
        $count = $maintenance->cleanup();

        return back()->with('success', "Limpeza concluída: {$count} item(ns).");
    }

    public function applyRetention(Request $request, MaintenanceService $maintenance): RedirectResponse
    {
        abort_unless($request->user()->can('maintenance.apply_retention'), 403);
        $request->validate(['confirm' => ['accepted']]);
        $maintenance->applyRetention();

        return back()->with('success', 'Política de retenção aplicada.');
    }
}
