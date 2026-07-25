<?php

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Models\MessageSendAttempt;
use App\Services\AuditLogger;
use App\Services\Conversations\ConversationMetricsService;
use App\Services\MessageProcessing\SendingRateLimiterService;
use App\Services\MessageProcessing\SendingSettingsService;
use App\Services\Reports\ErrorClassificationService;
use App\Services\Reports\ReportMetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request, ReportMetricsService $metrics, AuditLogger $audit): View
    {
        abort_unless($request->user()->can('reports.view'), 403);
        [$from, $to] = $this->period($request);
        $audit->log('report.viewed', 'Visao geral de relatorios visualizada.', null, null, ['from' => $from->toDateString(), 'to' => $to->toDateString()]);

        return view('admin.reports.index', ['metrics' => $metrics->overview($from, $to), 'from' => $from, 'to' => $to]);
    }

    public function batches(Request $request): View
    {
        abort_unless($request->user()->can('reports.view'), 403);
        $batches = MessageBatch::query()->with(['template', 'creator'])->latest()->paginate(25);

        return view('admin.reports.batches', ['batches' => $batches]);
    }

    public function messages(Request $request, ReportMetricsService $metrics): View
    {
        abort_unless($request->user()->can('reports.view'), 403);
        [$from, $to] = $this->period($request);

        return view('admin.reports.messages', ['totals' => $metrics->messageTotals($from, $to), 'from' => $from, 'to' => $to]);
    }

    public function errors(Request $request, ReportMetricsService $metrics, ErrorClassificationService $classifier): View
    {
        abort_unless($request->user()->can('reports.view'), 403);
        [$from, $to] = $this->period($request);

        return view('admin.reports.errors', ['errors' => $metrics->errorGroups($from, $to), 'classifier' => $classifier]);
    }

    public function notSent(Request $request): View
    {
        abort_unless($request->user()->can('reports.view'), 403);
        $recipients = MessageBatchRecipient::query()->with('batch')->whereIn('processing_status', ['skipped', 'cancelled', 'failed_permanent'])->latest()->paginate(25);

        return view('admin.reports.not-sent', ['recipients' => $recipients]);
    }

    public function attempts(Request $request): View
    {
        abort_unless($request->user()->can('reports.view'), 403);
        $attempts = MessageSendAttempt::query()->with('recipient.batch')->latest()->paginate(25);

        return view('admin.reports.attempts', ['attempts' => $attempts]);
    }

    public function rateLimits(Request $request, SendingSettingsService $settings, SendingRateLimiterService $limits): View
    {
        abort_unless($request->user()->can('reports.view_operational_metrics'), 403);
        $current = $settings->current();

        return view('admin.reports.rate-limits', ['settings' => $current, 'limits' => $limits->check($current)]);
    }

    public function contacts(Request $request, ReportMetricsService $metrics): View
    {
        abort_unless($request->user()->can('reports.view'), 403);

        return view('admin.reports.contacts', ['totals' => $metrics->contactTotals()]);
    }

    public function templates(Request $request, ReportMetricsService $metrics): View
    {
        abort_unless($request->user()->can('reports.view'), 403);
        [$from, $to] = $this->period($request);

        return view('admin.reports.templates', ['templates' => $metrics->templateTotals($from, $to)]);
    }

    public function conversations(Request $request, ConversationMetricsService $metrics): View
    {
        abort_unless($request->user()->can('reports.view'), 403);

        return view('admin.reports.conversations', ['totals' => $metrics->summary()]);
    }

    private function period(Request $request): array
    {
        return [
            $request->filled('from') ? Carbon::parse($request->input('from'))->startOfDay() : now()->subDays(30)->startOfDay(),
            $request->filled('to') ? Carbon::parse($request->input('to'))->endOfDay() : now()->endOfDay(),
        ];
    }
}
