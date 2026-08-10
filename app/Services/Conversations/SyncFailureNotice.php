<?php

namespace App\Services\Conversations;

use App\Enums\WhatsAppConnectionStatus;
use App\Models\ConversationSyncRun;
use App\Models\WhatsAppConnection;
use Carbon\CarbonInterface;

/**
 * Separa "a sincronização está falhando" de "falhou antes de reconectar".
 *
 * A tela mostra sempre a última execução. Quando a sessão do WhatsApp cai, a
 * sincronização falha a cada 15 minutos até alguém reconectar — e a execução
 * seguinte já volta a funcionar sozinha. No intervalo entre a reconexão e a
 * próxima execução, a tela continua exibindo a última falha em vermelho, com
 * "conecte o WhatsApp antes de sincronizar", enquanto a tela de conexão diz
 * "Conectado".
 *
 * As duas estão certas, e é justamente por isso que confunde: quem lê conclui
 * que o sistema está quebrado agora, quando o problema já passou. Foi essa
 * leitura que trouxe a dúvida até aqui.
 *
 * A conta é simples: se a conexão subiu **depois** de a execução terminar, a
 * falha é anterior à reconexão e não descreve o estado de hoje.
 */
class SyncFailureNotice
{
    /**
     * Erros que dizem respeito à conexão, e só eles.
     *
     * Uma falha de outra natureza não é resolvida por reconectar, e apresentá-la
     * como superada esconderia um problema real.
     */
    private const CONNECTION_ERRORS = [
        'WHATSAPP_NOT_CONNECTED',
        'WHATSAPP_SESSION_UNAVAILABLE',
    ];

    /**
     * @return array{superada: bool, reconectado_em: ?CarbonInterface}|null
     *                                                                     nulo quando não há falha de conexão a explicar
     */
    public function for(?ConversationSyncRun $run): ?array
    {
        if (! $run || ! in_array((string) $run->error_code, self::CONNECTION_ERRORS, true)) {
            return null;
        }

        $conexao = WhatsAppConnection::query()->latest('id')->first();
        $reconectadoEm = $conexao?->connected_at;

        $superada = $conexao?->status === WhatsAppConnectionStatus::Connected
            && $reconectadoEm !== null
            && $run->finished_at !== null
            && $reconectadoEm->greaterThan($run->finished_at);

        return [
            'superada' => $superada,
            'reconectado_em' => $superada ? $reconectadoEm : null,
        ];
    }
}
