<?php

namespace App\Console\Commands;

use App\Enums\MediaStorageStatus;
use App\Models\ConversationMessageMedium;
use App\Services\Conversations\ConversationMediaService;
use Illuminate\Console\Command;

/**
 * Apaga o arquivo de mídia vencido e mantém o registro.
 *
 * É foto de gente que está no nosso disco, e guardar para sempre não é uma
 * decisão que se toma por omissão. Passados os dias configurados o conteúdo sai
 * e a linha fica marcada como expirada — a conversa continua podendo dizer que
 * havia uma foto ali, que é diferente de fingir que nunca houve.
 *
 * A mensagem, a transcrição e a descrição não são tocadas: elas são texto, não
 * ocupam espaço relevante e são o que os relatórios leem.
 */
class PruneConversationMediaCommand extends Command
{
    protected $signature = 'conversations:prune-attachments
        {--limit=500 : Máximo de arquivos por execução}
        {--dry-run : Mostra o que apagaria, sem apagar nada}';

    protected $description = 'Apaga arquivos de mídia que passaram do prazo de retenção.';

    public function handle(ConversationMediaService $media): int
    {
        $vencidas = ConversationMessageMedium::query()
            ->where('status', MediaStorageStatus::Stored)
            ->whereNotNull('purge_after')
            ->where('purge_after', '<=', now())
            ->orderBy('purge_after')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($vencidas->isEmpty()) {
            $this->info('Nenhum arquivo vencido.');

            return self::SUCCESS;
        }

        $bytes = 0;

        foreach ($vencidas as $vencida) {
            $bytes += (int) $vencida->size_bytes;

            if ($this->option('dry-run')) {
                $this->line(sprintf('mensagem %-6s %s (%s)', $vencida->conversation_message_id, $vencida->path, $vencida->purge_after));

                continue;
            }

            $media->purge($vencida);
        }

        $mb = number_format($bytes / 1048576, 1, ',', '.');

        if ($this->option('dry-run')) {
            $this->comment('Simulação: '.$vencidas->count().' arquivos, '.$mb.' MB.');

            return self::SUCCESS;
        }

        $this->info($vencidas->count().' arquivos apagados, '.$mb.' MB liberados.');

        return self::SUCCESS;
    }
}
