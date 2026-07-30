<?php

namespace App\Console\Commands;

use App\Models\AiRun;
use App\Services\SystemSettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Retenção das execuções de IA.
 *
 * Remove apenas o log de execução. Insights persistidos, classificações e as
 * mensagens originais permanecem intactos.
 */
class AiPruneRunsCommand extends Command
{
    protected $signature = 'ai:prune-runs {--days= : Sobrescreve a retenção configurada} {--dry-run : Apenas conta}';

    protected $description = 'Aplica a retenção configurada as execuções de IA.';

    public function handle(SystemSettingService $settings): int
    {
        $days = (int) ($this->option('days') ?: $settings->get('ai.runs_retention_days', 90));

        if ($days < 1) {
            $this->error('A retenção deve ser de ao menos um dia.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days);
        $query = AiRun::query()->where('created_at', '<', $cutoff);
        $total = (int) $query->count();

        if ($this->option('dry-run')) {
            $this->info("Execucoes anteriores a {$cutoff->format('d/m/Y')}: {$total}.");

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info("Execuções de IA removidas: {$deleted} (retenção de {$days} dias).");

        return self::SUCCESS;
    }
}
