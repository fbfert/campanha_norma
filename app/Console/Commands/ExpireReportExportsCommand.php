<?php

namespace App\Console\Commands;

use App\Services\Maintenance\MaintenanceService;
use Illuminate\Console\Command;

class ExpireReportExportsCommand extends Command
{
    protected $signature = 'reports:expire-exports';

    protected $description = 'Expira exportações de relatórios vencidas.';

    public function handle(MaintenanceService $maintenance): int
    {
        $count = $maintenance->expireExports();
        $this->info("Exportações expiradas: {$count}");

        return self::SUCCESS;
    }
}
