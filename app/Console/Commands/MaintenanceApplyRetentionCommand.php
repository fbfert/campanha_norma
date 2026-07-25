<?php

namespace App\Console\Commands;

use App\Services\Maintenance\MaintenanceService;
use Illuminate\Console\Command;

class MaintenanceApplyRetentionCommand extends Command
{
    protected $signature = 'maintenance:apply-retention';

    protected $description = 'Aplica politica de retencao preservando historico necessario.';

    public function handle(MaintenanceService $maintenance): int
    {
        if (app()->environment('production') && ! $this->confirm('Aplicar retencao em producao?')) {
            return self::FAILURE;
        }

        $result = $maintenance->applyRetention();
        $this->info('Retencao aplicada: '.json_encode($result));

        return self::SUCCESS;
    }
}
