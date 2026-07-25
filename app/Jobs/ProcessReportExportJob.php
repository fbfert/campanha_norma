<?php

namespace App\Jobs;

use App\Models\ReportExport;
use App\Services\Reports\ReportExportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessReportExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $exportId)
    {
        $this->onQueue('whatsapp-maintenance');
    }

    public function handle(ReportExportService $service): void
    {
        $export = ReportExport::query()->find($this->exportId);

        if ($export) {
            $service->process($export);
        }
    }
}
