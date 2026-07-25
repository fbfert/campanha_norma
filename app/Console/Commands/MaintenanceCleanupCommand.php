<?php

namespace App\Console\Commands;

use App\Services\Maintenance\MaintenanceService;
use Illuminate\Console\Command;

class MaintenanceCleanupCommand extends Command
{
    protected $signature = 'maintenance:cleanup';

    protected $description = 'Executa limpeza operacional segura.';

    public function handle(MaintenanceService $maintenance): int
    {
        $count = $maintenance->cleanup();
        $this->info("Itens limpos: {$count}");

        return self::SUCCESS;
    }
}
