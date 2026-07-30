<?php

namespace App\Console\Commands;

use App\Models\KnowledgeRetrieval;
use App\Services\SystemSettingService;
use Illuminate\Console\Command;

/**
 * Retencao do log de recuperacao.
 *
 * Remove o registro de busca e os trechos que ele guardava. Nao toca em
 * `reply_suggestion_citations`: a explicacao de uma resposta enviada tem outro
 * ciclo de vida, mais longo, porque ela justifica algo que chegou a uma pessoa.
 */
class KnowledgePruneRetrievalsCommand extends Command
{
    protected $signature = 'knowledge:prune-retrievals {--days= : Sobrescreve a retencao configurada} {--dry-run : Apenas conta}';

    protected $description = 'Aplica a retencao configurada ao log de recuperacao da base de conhecimento.';

    public function handle(SystemSettingService $settings): int
    {
        $days = (int) ($this->option('days') ?: $settings->get('knowledge.retrieval_retention_days', 180));

        if ($days < 1) {
            $this->info('Retencao desligada: nada a remover.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $query = KnowledgeRetrieval::query()->where('created_at', '<', $cutoff);
        $total = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->line("{$total} registro(s) de recuperacao anteriores a {$cutoff->toDateString()} seriam removidos.");

            return self::SUCCESS;
        }

        $removed = 0;
        // Exclusao pelo model para que a cascata dos trechos acompanhe.
        $query->select('id')->chunkById(500, function ($rows) use (&$removed): void {
            foreach ($rows as $row) {
                $row->delete();
                $removed++;
            }
        });

        $this->info("{$removed} registro(s) de recuperacao removidos.");

        return self::SUCCESS;
    }
}
