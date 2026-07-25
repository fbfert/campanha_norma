<?php

namespace App\Services\Reports;

use App\Models\Contact;
use App\Models\MessageBatch;
use App\Models\MessageBatchRecipient;
use App\Models\MessageSendAttempt;
use App\Models\MessageTemplate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportMetricsService
{
    public function overview(?Carbon $from = null, ?Carbon $to = null): array
    {
        $from ??= now()->subDays(30)->startOfDay();
        $to ??= now()->endOfDay();

        return [
            'messages' => $this->messageTotals($from, $to),
            'batches' => $this->batchTotals($from, $to),
            'errors' => $this->errorGroups($from, $to),
            'contacts' => $this->contactTotals(),
            'templates' => $this->templateTotals($from, $to),
            'charts' => [
                'messages_by_day' => $this->messagesByDay($from, $to),
                'errors_top' => $this->errorGroups($from, $to)->take(10),
                'batches_by_status' => MessageBatch::query()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status'),
            ],
        ];
    }

    public function messageTotals(Carbon $from, Carbon $to): array
    {
        $query = MessageBatchRecipient::query()->whereBetween('created_at', [$from, $to]);
        $processed = (clone $query)->whereIn('processing_status', ['sent', 'failed_permanent', 'failed_temporary', 'cancelled', 'skipped'])->count();
        $sent = (clone $query)->where('processing_status', 'sent')->count();
        $failed = (clone $query)->whereIn('processing_status', ['failed_permanent', 'failed_temporary'])->count();
        $cancelled = (clone $query)->where('processing_status', 'cancelled')->count();
        $skipped = (clone $query)->where('processing_status', 'skipped')->count();
        $unknown = (clone $query)->where('error_code', 'SEND_RESULT_UNKNOWN')->count();
        $attempts = MessageSendAttempt::query()->whereBetween('created_at', [$from, $to])->count();

        return [
            'prepared' => (clone $query)->count(),
            'processed' => $processed,
            'sent' => $sent,
            'failed' => $failed,
            'cancelled' => $cancelled,
            'skipped' => $skipped,
            'unknown' => $unknown,
            'attempts' => $attempts,
            'average_attempts' => $processed > 0 ? round($attempts / $processed, 2) : null,
            'success_rate' => $processed > 0 ? round(($sent / $processed) * 100, 2) : null,
        ];
    }

    public function batchTotals(Carbon $from, Carbon $to): array
    {
        return [
            'total' => MessageBatch::query()->whereBetween('created_at', [$from, $to])->count(),
            'processing' => MessageBatch::query()->where('status', 'processing')->count(),
            'paused' => MessageBatch::query()->where('status', 'paused')->count(),
            'completed_today' => MessageBatch::query()->whereDate('completed_at', today())->count(),
        ];
    }

    public function errorGroups(Carbon $from, Carbon $to)
    {
        return MessageBatchRecipient::query()
            ->select('error_code', DB::raw('count(*) as total'), DB::raw('min(failed_at) as first_seen'), DB::raw('max(failed_at) as last_seen'))
            ->whereNotNull('error_code')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('error_code')
            ->orderByDesc('total')
            ->get();
    }

    public function contactTotals(): array
    {
        return [
            'total' => Contact::query()->count(),
            'active' => Contact::query()->where('status', 'active')->count(),
            'inactive' => Contact::query()->where('status', 'inactive')->count(),
            'blocked' => Contact::query()->where('status', 'blocked')->count(),
            'do_not_contact' => Contact::query()->where('do_not_contact', true)->count(),
            'used_in_batches' => MessageBatchRecipient::query()->distinct('contact_id')->count('contact_id'),
            'never_used' => Contact::query()->whereNotIn('id', MessageBatchRecipient::query()->select('contact_id'))->count(),
        ];
    }

    public function templateTotals(Carbon $from, Carbon $to)
    {
        return MessageTemplate::query()
            ->withCount(['batches as batches_count' => fn ($query) => $query->whereBetween('created_at', [$from, $to])])
            ->orderBy('name')
            ->get();
    }

    public function messagesByDay(Carbon $from, Carbon $to)
    {
        return MessageBatchRecipient::query()
            ->selectRaw('date(created_at) as day, processing_status, count(*) as total')
            ->whereBetween('created_at', [$from, $to])
            ->groupBy('day', 'processing_status')
            ->orderBy('day')
            ->get();
    }
}
