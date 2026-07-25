<?php

namespace App\Console\Commands;

use App\Services\AuditLogger;
use App\Services\Monitoring\HeartbeatService;
use App\Services\Monitoring\MonitoringService;
use Illuminate\Console\Command;

class MonitoringCheckCommand extends Command
{
    protected $signature = 'monitoring:check';

    protected $description = 'Executa diagnostico operacional basico.';

    public function handle(MonitoringService $monitoring, HeartbeatService $heartbeat, AuditLogger $audit): int
    {
        $heartbeat->scheduler();
        $items = $monitoring->diagnostics();
        $audit->log('monitoring.diagnostic_run', 'Diagnostico operacional executado.', null, null, ['items' => array_keys($items)]);

        foreach ($items as $name => $item) {
            $this->line($name.': '.$item['status']->value.' - '.$item['message']);
        }

        return self::SUCCESS;
    }
}
