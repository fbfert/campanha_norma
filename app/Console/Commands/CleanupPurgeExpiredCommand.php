<?php

namespace App\Console\Commands;

use App\Services\Cleanup\CleanupService;
use Illuminate\Console\Command;

/**
 * O expurgo da lixeira da Limpeza.
 *
 * É o que dá sentido ao prazo: sem ele, "lixeira reversível por 30 dias" seria
 * só um rótulo, e nada sairia do banco nunca. Roda uma vez por dia — depois
 * daqui, o que foi limpo não volta mais.
 */
class CleanupPurgeExpiredCommand extends Command
{
    protected $signature = 'cleanup:purge-expired';

    protected $description = 'Apaga em definitivo as limpezas cujo prazo na lixeira venceu.';

    public function handle(CleanupService $cleanup): int
    {
        $resultado = $cleanup->expurgarVencidas();

        $this->info("Expurgo concluído: {$resultado['limpezas']} "
            .($resultado['limpezas'] === 1 ? 'operação' : 'operações')
            .", {$resultado['registros']} "
            .($resultado['registros'] === 1 ? 'registro' : 'registros').'.');

        return self::SUCCESS;
    }
}
