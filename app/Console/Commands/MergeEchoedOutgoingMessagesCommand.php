<?php

namespace App\Console\Commands;

use App\Enums\ConversationMessageDirection;
use App\Models\ConversationMessage;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Junta a mensagem enviada com o eco dela que virou uma segunda linha.
 *
 * O WhatsApp Web às vezes entrega a mensagem e ainda assim lança erro, sem
 * devolver o identificador — a linha fica gravada sem `external_message_id`.
 * Quando a sincronização traz o mesmo texto de volta, não há por onde casar os
 * dois registros, e a mesma frase aparece duas vezes na conversa.
 *
 * `ConversationSyncService::adoptOwnMessage()` passou a reconhecer o eco na
 * entrada. Este comando trata o que já estava gravado, com a mesma escolha:
 * fica a linha original — é para ela que o resto do sistema aponta — e o eco
 * entrega o identificador que faltava antes de sair.
 */
class MergeEchoedOutgoingMessagesCommand extends Command
{
    protected $signature = 'conversations:merge-echoed-messages
        {--window=10 : Minutos de folga entre as duas linhas}
        {--apply : Executa de verdade; sem isto apenas simula}';

    protected $description = 'Junta mensagens de saída duplicadas pelo eco da sincronização.';

    /**
     * Tabelas que apontam para `conversation_messages` e precisam seguir a
     * linha que fica.
     *
     * @var array<string, string>
     */
    private const REFERENCES = [
        'conversation_events' => 'conversation_message_id',
        'conversation_flow_question_usages' => 'conversation_message_id',
        'conversation_flow_transitions' => 'conversation_message_id',
        'conversation_message_classifications' => 'conversation_message_id',
        'message_transcriptions' => 'conversation_message_id',
        'conversation_flow_states' => 'last_processed_message_id',
    ];

    public function handle(AuditLogger $audit): int
    {
        $janela = max(1, (int) $this->option('window'));
        $aplicar = (bool) $this->option('apply');

        // Consulta crua não conhece exclusão suave: sem estes dois filtros o
        // comando voltaria a mesclar mensagem que a Limpeza já tirou do ar.
        $pares = DB::table('conversation_messages as original')
            ->whereNull('original.deleted_at')
            ->whereNull('eco.deleted_at')
            ->join('conversation_messages as eco', function ($join) use ($janela): void {
                $join->on('original.conversation_id', '=', 'eco.conversation_id')
                    ->on('original.body', '=', 'eco.body')
                    ->on('original.id', '<', 'eco.id')
                    ->whereRaw('abs(timestampdiff(minute, original.created_at, eco.created_at)) <= ?', [$janela]);
            })
            ->where('original.direction', ConversationMessageDirection::Outgoing->value)
            ->where('eco.direction', ConversationMessageDirection::Outgoing->value)
            ->whereNull('original.external_message_id')
            ->whereNotNull('eco.external_message_id')
            ->orderBy('original.id')
            ->select([
                'original.id as original_id',
                'eco.id as eco_id',
                'eco.external_message_id as external_message_id',
                'original.conversation_id as conversation_id',
                'original.body as body',
            ])
            ->get();

        // A junção pode devolver o mesmo eco casando com várias linhas de texto
        // idêntico — "ping" enviado três vezes seguidas gera exatamente isso.
        // Cada linha só pode entrar em um par: sem isto, o identificador do
        // primeiro par seria oferecido de novo no segundo, e o índice único
        // recusaria com a mensagem errada, apontando para a linha que ficou.
        $usados = [];
        $pares = $pares->filter(function (object $par) use (&$usados): bool {
            if (isset($usados[$par->original_id]) || isset($usados[$par->eco_id])) {
                return false;
            }

            $usados[$par->original_id] = true;
            $usados[$par->eco_id] = true;

            return true;
        })->values();

        $this->info("{$pares->count()} par(es) de mensagem de saída duplicada.");
        $this->line('');

        foreach ($pares->take(5) as $par) {
            $this->line(sprintf(
                '  conversa %-5s fica msg %-5s (recebe %s) | sai msg %s :: %s',
                $par->conversation_id,
                $par->original_id,
                $par->external_message_id,
                $par->eco_id,
                mb_substr((string) $par->body, 0, 50),
            ));
        }

        $this->line('');

        if (! $aplicar) {
            $this->info('Simulação. Use --apply para executar.');

            return self::SUCCESS;
        }

        $juntadas = 0;

        foreach ($pares as $par) {
            DB::transaction(function () use ($par): void {
                foreach (self::REFERENCES as $tabela => $coluna) {
                    DB::table($tabela)->where($coluna, $par->eco_id)->update([$coluna => $par->original_id]);
                }

                // O eco sai primeiro: `provider` + `external_message_id` tem
                // índice único, e transferir o identificador com as duas
                // linhas ainda no banco esbarra nele.
                ConversationMessage::query()->whereKey($par->eco_id)->delete();

                ConversationMessage::query()->whereKey($par->original_id)
                    ->update(['external_message_id' => $par->external_message_id]);
            });

            $juntadas++;
        }

        $audit->log('conversations.echoed_messages_merged', 'Mensagens de saída duplicadas pelo eco foram juntadas.', null, null, [
            'total' => $juntadas,
            'janela_minutos' => $janela,
        ]);

        $this->info("{$juntadas} par(es) juntado(s).");

        return self::SUCCESS;
    }
}
