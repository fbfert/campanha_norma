<?php

namespace App\Services\Ai;

use App\Enums\AiRunStatus;
use App\Models\AiRun;
use App\Models\ConversationInsight;
use App\Models\ConversationMessageClassification;
use App\Services\SystemSettingService;
use Illuminate\Support\Carbon;

class AiMonitoringService
{
    public function __construct(private readonly SystemSettingService $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function metrics(int $days = 7): array
    {
        $since = Carbon::now()->subDays(max(1, $days))->startOfDay();

        $runs = AiRun::query()->where('created_at', '>=', $since);

        $byStatus = (clone $runs)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $byProvider = (clone $runs)
            ->selectRaw('provider, status, count(*) as total')
            ->groupBy('provider', 'status')
            ->get();

        return [
            'since' => $since,
            'total' => (clone $runs)->count(),
            'by_status' => $byStatus,
            'succeeded' => $byStatus[AiRunStatus::Succeeded->value] ?? 0,
            'failed' => $byStatus[AiRunStatus::Failed->value] ?? 0,
            'invalid_output' => $byStatus[AiRunStatus::InvalidOutput->value] ?? 0,
            'average_latency_ms' => (int) round((float) (clone $runs)->whereNotNull('latency_ms')->avg('latency_ms')),
            'max_latency_ms' => (int) (clone $runs)->max('latency_ms'),
            'total_tokens' => (int) (clone $runs)->sum('total_tokens'),
            'estimated_cost' => (float) (clone $runs)->sum('estimated_cost'),
            'failures_by_provider' => $byProvider
                ->where('status', AiRunStatus::Failed->value)
                ->pluck('total', 'provider')
                ->all(),
            'errors_by_code' => (clone $runs)
                ->whereNotNull('error_code')
                ->selectRaw('error_code, count(*) as total')
                ->groupBy('error_code')
                ->pluck('total', 'error_code')
                ->all(),
            'low_confidence' => ConversationInsight::query()
                ->where('created_at', '>=', $since)
                ->where('review_reason', 'low_confidence')
                ->count(),
            'awaiting_review' => ConversationInsight::query()->where('requires_human_review', true)->where('reviewed', false)->count(),
            'classifications_awaiting_review' => ConversationMessageClassification::query()->where('requires_human_review', true)->count(),
            'stuck' => $this->stuck()->count(),
        ];
    }

    /**
     * Execuções presas: iniciadas e nunca finalizadas dentro do limite.
     */
    public function stuck()
    {
        $minutes = max(1, (int) $this->settings->get('ai.stuck_run_minutes', 15));

        return AiRun::query()
            ->whereIn('status', [AiRunStatus::Pending->value, AiRunStatus::Running->value])
            ->where('started_at', '<', Carbon::now()->subMinutes($minutes));
    }
}
