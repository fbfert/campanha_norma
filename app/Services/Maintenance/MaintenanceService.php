<?php

namespace App\Services\Maintenance;

use App\Enums\ReportExportStatus;
use App\Models\MessageBatch;
use App\Models\ReportExport;
use App\Services\AuditLogger;
use App\Services\MessageProcessing\BatchProgressService;
use App\Services\SystemSettingService;
use Illuminate\Support\Facades\Storage;

class MaintenanceService
{
    public function __construct(
        private readonly BatchProgressService $progress,
        private readonly SystemSettingService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function syncCounters(): int
    {
        $count = 0;
        MessageBatch::query()->each(function ($batch) use (&$count): void {
            $this->progress->sync($batch);
            $count++;
        });
        $this->audit->log('maintenance.sync_counters', 'Contadores sincronizados.', null, null, ['batches' => $count]);

        return $count;
    }

    public function expireExports(): int
    {
        $count = 0;
        ReportExport::query()
            ->where('status', ReportExportStatus::Completed)
            ->where('expires_at', '<=', now())
            ->each(function (ReportExport $export) use (&$count): void {
                if ($export->file_path) {
                    Storage::disk('local')->delete($export->file_path);
                }
                $export->update(['status' => ReportExportStatus::Expired]);
                $count++;
            });

        return $count;
    }

    public function cleanup(): int
    {
        $count = $this->expireExports();
        $this->audit->log('maintenance.cleanup', 'Limpeza operacional executada.', null, null, ['expired_exports' => $count]);

        return $count;
    }

    public function applyRetention(): array
    {
        $expiredExports = $this->expireExports();
        $this->audit->log('maintenance.retention_applied', 'Política de retenção aplicada preservando histórico.', null, null, ['expired_exports' => $expiredExports]);

        return ['expired_exports' => $expiredExports, 'history_preserved' => true];
    }
}
