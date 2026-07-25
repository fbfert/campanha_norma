<?php

namespace App\Console\Commands;

use App\Services\Monitoring\MonitoringService;
use Illuminate\Console\Command;

class MaintenanceFindInconsistenciesCommand extends Command
{
    protected $signature = 'maintenance:find-inconsistencies';

    protected $description = 'Lista inconsistencias operacionais conhecidas.';

    public function handle(MonitoringService $monitoring): int
    {
        $item = $monitoring->inconsistentBatches();
        $this->line($item['status']->value.' - '.$item['message'].' Total: '.($item['details']['count'] ?? 0));

        return self::SUCCESS;
    }
}
