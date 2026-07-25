<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Enums\ReportExportStatus;
use App\Http\Controllers\Controller;
use App\Models\ReportExport;
use App\Services\AuditLogger;
use App\Services\Reports\ReportExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportExportController extends Controller
{
    public function store(Request $request, ReportExportService $service): RedirectResponse
    {
        abort_unless($request->user()->can('reports.export') || $request->user()->can('histories.export'), 403);
        $data = $request->validate([
            'report_type' => ['required', 'string'],
            'format' => ['required', 'in:csv,xlsx'],
            'columns' => ['nullable', 'array'],
        ]);

        $export = $service->request($request->user(), $data['report_type'], $data['format'], $request->except(['_token', 'columns', 'format', 'report_type']), $data['columns'] ?? null);

        return redirect()->route('admin.report-exports.show', $export)->with('success', 'Exportacao solicitada.');
    }

    public function index(Request $request): View
    {
        abort_unless($request->user()->can('reports.export') || $request->user()->can('histories.export'), 403);

        return view('admin.report-exports.index', ['exports' => ReportExport::with('user')->latest()->paginate(25)]);
    }

    public function show(Request $request, ReportExport $export): View
    {
        abort_unless($request->user()->can('reports.export') || $request->user()->can('histories.export'), 403);

        return view('admin.report-exports.show', ['export' => $export]);
    }

    public function download(Request $request, ReportExport $export, AuditLogger $audit): BinaryFileResponse
    {
        abort_unless($request->user()->can('reports.export') || $request->user()->can('histories.export'), 403);
        abort_unless($export->status === ReportExportStatus::Completed && $export->file_path && ! $export->expires_at?->isPast(), 404);
        $audit->log('report.export_downloaded', 'Exportacao baixada.', $export, null, ['report_type' => $export->report_type, 'format' => $export->format], $request->user());

        return response()->download(Storage::disk('local')->path($export->file_path));
    }

    public function retry(Request $request, ReportExport $export, ReportExportService $service): RedirectResponse
    {
        abort_unless($request->user()->can('reports.export'), 403);
        abort_unless($export->status === ReportExportStatus::Failed, 403);
        $service->process($export);

        return back()->with('success', 'Exportacao reprocessada.');
    }

    public function destroy(Request $request, ReportExport $export): RedirectResponse
    {
        abort_unless($request->user()->can('reports.export'), 403);
        if ($export->file_path) {
            Storage::disk('local')->delete($export->file_path);
        }
        $export->update(['status' => ReportExportStatus::Cancelled]);

        return back()->with('success', 'Exportacao cancelada.');
    }
}
