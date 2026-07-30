<?php

namespace App\Console\Commands;

use App\Services\Analytics\DailyMetricBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Reconstrução das métricas diárias de participação.
 *
 * Segue o mesmo padrão do `reports:rebuild-metrics` da Etapa 6: intervalo
 * explícito, escrita por chave natural e repetição segura. Não apaga nada e não
 * altera conversa nenhuma — le o que já aconteceu e grava a contagem.
 */
class AnalyticsRebuildMetricsCommand extends Command
{
    protected $signature = 'analytics:rebuild-metrics
        {--date= : Reconstroi um único dia (AAAA-MM-DD)}
        {--from= : Início do intervalo}
        {--to= : Fim do intervalo}
        {--days= : Reconstroi os últimos N dias}';

    protected $description = 'Reconstroi as métricas diárias de participação da pesquisa conversacional.';

    public function handle(DailyMetricBuilder $builder): int
    {
        [$from, $to] = $this->interval();

        if ($from->gt($to)) {
            $this->error('O início do intervalo e posterior ao fim.');

            return self::FAILURE;
        }

        $this->line("Reconstruindo de {$from->toDateString()} até {$to->toDateString()}.");

        $rows = $builder->rebuild($from, $to);

        $this->info("{$rows} linha(s) escrita(s). Reconstruir de novo o mesmo intervalo produz o mesmo resultado.");

        return self::SUCCESS;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function interval(): array
    {
        if ($date = $this->option('date')) {
            $day = Carbon::parse($date);

            return [$day->copy()->startOfDay(), $day->copy()->endOfDay()];
        }

        if ($days = $this->option('days')) {
            return [now()->subDays((int) $days - 1)->startOfDay(), now()->endOfDay()];
        }

        $from = Carbon::parse($this->option('from') ?: now()->subDay()->toDateString())->startOfDay();
        $to = Carbon::parse($this->option('to') ?: $from->toDateString())->endOfDay();

        return [$from, $to];
    }
}
