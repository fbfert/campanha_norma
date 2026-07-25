<?php

namespace App\Console\Commands;

use App\Models\DailyMessageMetric;
use App\Services\Reports\ReportMetricsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RebuildReportMetricsCommand extends Command
{
    protected $signature = 'reports:rebuild-metrics {--date=} {--from=} {--to=} {--batch=} {--force}';

    protected $description = 'Reconstroi metricas diarias de mensagens.';

    public function handle(ReportMetricsService $metrics): int
    {
        $from = Carbon::parse($this->option('date') ?: $this->option('from') ?: now()->subDay()->toDateString())->startOfDay();
        $to = Carbon::parse($this->option('date') ?: $this->option('to') ?: $from->toDateString())->endOfDay();
        $days = 0;

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $totals = $metrics->messageTotals($day->copy()->startOfDay(), $day->copy()->endOfDay());
            DailyMessageMetric::query()->updateOrCreate(
                ['date' => $day->toDateString(), 'provider' => config('whatsapp.provider', 'web')],
                [
                    'total_prepared' => $totals['prepared'],
                    'total_processed' => $totals['processed'],
                    'total_sent' => $totals['sent'],
                    'total_failed' => $totals['failed'],
                    'total_cancelled' => $totals['cancelled'],
                    'total_skipped' => $totals['skipped'],
                    'total_attempts' => $totals['attempts'],
                    'total_waiting_minutes' => 0,
                ]
            );
            $days++;
        }

        $this->info("Metricas reconstruidas para {$days} dia(s).");

        return self::SUCCESS;
    }
}
