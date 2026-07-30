<?php

namespace App\Console\Commands;

use App\Services\Maintenance\MaintenanceService;
use Illuminate\Console\Command;

class MaintenanceApplyRetentionCommand extends Command
{
    protected $signature = 'maintenance:apply-retention';

    protected $description = 'Aplica política de retenção preservando histórico necessário.';

    public function handle(MaintenanceService $maintenance): int
    {
        if (app()->environment('production') && ! $this->confirm('Aplicar retenção em produção?')) {
            return self::FAILURE;
        }

        $result = $maintenance->applyRetention();
        $this->info('Retenção aplicada: '.json_encode($result));

        return self::SUCCESS;
    }
}
