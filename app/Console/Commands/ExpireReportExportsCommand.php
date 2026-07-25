<?php

namespace App\Console\Commands;

use App\Services\Maintenance\MaintenanceService;
use Illuminate\Console\Command;

class ExpireReportExportsCommand extends Command
{
    protected $signature = 'reports:expire-exports';

    protected $description = 'Expira exportacoes de relatorios vencidas.';

    public function handle(MaintenanceService $maintenance): int
    {
        $count = $maintenance->expireExports();
        $this->info("Exportacoes expiradas: {$count}");

        return self::SUCCESS;
    }
}
