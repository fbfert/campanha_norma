<?php

namespace App\Console\Commands;

use App\Enums\ConversationMessageStatus;
use App\Models\ConversationMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recolhe a repetição deixada pela rede de segurança sem sessão.
 *
 * Enquanto não havia teto, uma sessão caída fazia a rede tentar o mesmo
 * agradecimento a cada cinco minutos e gravar uma linha por tentativa. Duas
 * conversas chegaram a 771 mensagens, sendo 13 reais, e metade da tabela de
 * mensagens virou repetição de duas frases que nunca saíram.
 *
 * O laço já não acontece — `PendingReplyResolver` não tenta sem sessão e tem
 * teto de tentativas. Este comando limpa o que ficou.
 *
 * **Guarda a primeira e a última tentativa de cada bloco.** Apagar tudo
 * apagaria o registro de que tentamos, que é informação real; guardar as 767
 * cópias no meio não acrescenta nada a isso. As duas pontas dizem quando
 * começou, quando parou e quantas foram.
 */
class PruneFailedAutomatedRepliesCommand extends Command
{
    protected $signature = 'conversations:prune-failed-replies
        {--conversation=* : Limita a conversas específicas}
        {--aplicar : Grava. Sem esta opção o comando apenas mostra o que faria}';

    protected $description = 'Recolhe tentativas repetidas de resposta automática que falharam.';

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $conversas = array_map('intval', (array) $this->option('conversation'));

        $base = ConversationMessage::query()
            ->where('status', ConversationMessageStatus::Failed)
            ->where('error_code', 'AUTOMATED_REPLY_FAILED')
            ->when($conversas, fn ($query) => $query->whereIn('conversation_id', $conversas));

        $porConversa = (clone $base)
            ->selectRaw('conversation_id, count(*) n, min(id) primeira, max(id) ultima') // ortografia:ignorar - apelido de coluna em SQL, que não leva acento
            ->groupBy('conversation_id')
            ->orderByDesc('n')
            ->get();

        if ($porConversa->isEmpty()) {
            $this->info('Nada a recolher.');

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($porConversa as $linha) {
            /*
             | As duas pontas ficam. Elas são o registro de que tentamos, de
             | quando começou e de quando parou — o que as cópias do meio não
             | acrescentam.
             */
            $apagaveis = (clone $base)
                ->where('conversation_id', $linha->conversation_id)
                ->whereNotIn('id', [$linha->primeira, $linha->ultima])
                ->count();

            $total += $apagaveis;

            $this->line(sprintf(
                '  conversa %-7s %4d tentativas, %4d a recolher, restam %d',
                $linha->conversation_id,
                $linha->n,
                $apagaveis,
                $linha->n - $apagaveis,
            ));

            if (! $aplicar) {
                continue;
            }

            DB::transaction(function () use ($base, $linha): void {
                (clone $base)
                    ->where('conversation_id', $linha->conversation_id)
                    ->whereNotIn('id', [$linha->primeira, $linha->ultima])
                    ->delete();
            });
        }

        $this->newLine();
        $this->info($total.' tentativas '.($aplicar ? 'recolhidas.' : 'seriam recolhidas.'));

        if (! $aplicar) {
            $this->warn('Nada foi gravado. Repita com --aplicar.');
        }

        return self::SUCCESS;
    }
}
