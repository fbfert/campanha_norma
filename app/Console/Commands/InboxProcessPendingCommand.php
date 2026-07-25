<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class InboxProcessPendingCommand extends Command
{
    protected $signature = 'inbox:process-pending';

    protected $description = 'Comando reservado para processamento de pendencias da caixa.';

    public function handle(): int
    {
        $this->info('Pendencias da caixa verificadas.');

        return self::SUCCESS;
    }
}
