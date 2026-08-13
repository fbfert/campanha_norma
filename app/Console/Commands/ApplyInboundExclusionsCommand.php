<?php

namespace App\Console\Commands;

use App\Services\InboundAttendance\InboundAttendanceQueue;
use App\Services\InboundAttendance\InboundAttendanceRouter;
use App\Services\InboundAttendance\InboundAttendanceService;
use Illuminate\Console\Command;

/**
 * Aplica as expressões de exclusão ao que já está parado na fila.
 *
 * A exclusão age quando a mensagem é processada, e isso deixa de fora
 * justamente o que motivou a lista: o aviso de operadora que já estava na fila
 * quando alguém percebeu que ele não devia estar ali.
 *
 * Fora do scheduler de propósito. É uma varredura que tira coisa da vista, e
 * varredura que acontece sozinha some do radar quando erra — a lista larga
 * demais engoliria uma pessoa e ninguém saberia de onde veio.
 */
class ApplyInboundExclusionsCommand extends Command
{
    protected $signature = 'inbound-attendance:apply-exclusions
        {--limit=200 : Máximo de conversas examinadas}
        {--dry-run : Mostra o que sairia da fila, sem gravar nada}';

    protected $description = 'Tira da fila as conversas paradas que casam com uma expressão de exclusão.';

    public function handle(
        InboundAttendanceService $attendance,
        InboundAttendanceQueue $queue,
        InboundAttendanceRouter $router,
    ): int {
        $limite = max(1, (int) $this->option('limit'));

        if ($router->exclusionExpressions() === []) {
            $this->warn('Nenhuma expressão de exclusão configurada.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $total = 0;

            foreach ($queue->pending()->orderByDesc('last_incoming_message_at')->limit($limite)->get() as $conversa) {
                $mensagem = $conversa->messages()->where('direction', 'incoming')->latest('id')->first();
                $expressao = $mensagem ? $router->exclusionMatch($mensagem) : null;

                if ($expressao === null) {
                    continue;
                }

                $total++;
                $this->line(sprintf('conversa %-5s casa com "%s"', $conversa->id, $expressao));
            }

            $this->comment('Simulação: nada foi gravado. '.$total.' sairiam da fila.');

            return self::SUCCESS;
        }

        $removidas = $attendance->applyExclusionsToPending($limite);
        $queue->forgetCount();

        $this->info($removidas.' '.($removidas === 1 ? 'conversa saiu da fila.' : 'conversas saíram da fila.'));

        return self::SUCCESS;
    }
}
