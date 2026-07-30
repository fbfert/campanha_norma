<?php

namespace App\Console\Commands;

use App\Services\Maintenance\MaintenanceService;
use Illuminate\Console\Command;

class MaintenanceSyncCountersCommand extends Command
{
    protected $signature = 'maintenance:sync-counters';

    protected $description = 'Sincroniza contadores de lotes via manutenção.';

    public function handle(MaintenanceService $maintenance): int
    {
        $count = $maintenance->syncCounters();
        $this->info("Lotes sincronizados: {$count}");

        return self::SUCCESS;
    }
}
