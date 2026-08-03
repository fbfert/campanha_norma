<?php

namespace App\Console\Commands;

use App\Models\ConversationMessage;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Corrige o fuso de `sent_at` e `received_at` gravados em UTC.
 *
 * O serviço Node entrega ISO-8601 em Greenwich, e o valor ia para o banco sem
 * conversão: a mesma linha mostrava 19h em `created_at` e 22h no horário
 * próprio da mensagem. A entrada foi corrigida; este comando trata o que já
 * estava gravado.
 *
 * Quem escolhe o que corrigir e a distância para o `created_at`. Mensagem que a
 * própria aplicação carimbou com `now()` tem os dois horários a segundos de
 * distância; tudo que veio do provedor esta a horas — três, quando o evento e
 * recente, ou dias, quando a mensagem foi importada depois pela sincronização.
 */
class FixMessageTimestampTimezoneCommand extends Command
{
    protected $signature = 'conversations:fix-timestamp-timezone
        {--offset=-3 : Horas a somar; -3 converte de Greenwich para o horário de Brasília}
        {--tolerance=10 : Minutos de folga em torno do deslocamento esperado}
        {--incluir-importadas : Também corrige mensagens trazidas pela sincronização, cujo created_at é a data do import}
        {--apply : Executa de verdade; sem isto apenas simula}';

    protected $description = 'Converte para o fuso local os horários de mensagem gravados em UTC.';

    public function handle(AuditLogger $audit): int
    {
        $offset = (int) $this->option('offset');
        $tolerancia = max(1, (int) $this->option('tolerance'));
        $importadas = (bool) $this->option('incluir-importadas');
        $aplicar = (bool) $this->option('apply');

        $esperado = abs($offset) * 60;

        // Por padrão só entram as linhas cujo horário está a exatamente um
        // deslocamento de distância do `created_at` — o retrato de quem foi
        // gravado em Greenwich agora há pouco. Depois de corrigida, a linha
        // passa a ter diferença de segundos e sai da faixa: rodar o comando
        // duas vezes não desloca nada de novo.
        //
        // Mensagem trazida pela sincronização não cabe nessa faixa: ali o
        // `created_at` é a hora do import, e o evento pode ser de dias antes.
        // Corrigir essas exige dizer explicitamente, porque a distância não
        // distingue mais o erro de fuso do intervalo real entre os dois.
        $condicao = fn ($query) => $importadas
            ? $query->whereRaw('abs(timestampdiff(minute, created_at, coalesce(sent_at, received_at))) >= ?', [$esperado - $tolerancia])
            : $query->whereRaw('abs(timestampdiff(minute, created_at, coalesce(sent_at, received_at))) between ? and ?', [$esperado - $tolerancia, $esperado + $tolerancia]);

        $alvos = ConversationMessage::query()
            ->where(fn ($query) => $query->whereNotNull('sent_at')->orWhereNotNull('received_at'))
            ->where($condicao)
            ->orderBy('id');

        $total = (clone $alvos)->count();
        $intocadas = ConversationMessage::query()
            ->where(fn ($query) => $query->whereNotNull('sent_at')->orWhereNotNull('received_at'))
            ->count() - $total;

        $this->info("{$total} mensagem(ns) a corrigir | {$intocadas} intocada(s), com horário já local.");
        $this->line('');
        $this->line('Amostra:');

        foreach ((clone $alvos)->limit(5)->get() as $mensagem) {
            $atual = $mensagem->sent_at ?? $mensagem->received_at;

            $this->line(sprintf(
                '  msg %-5s criada %s | %s %s -> %s',
                $mensagem->id,
                $mensagem->created_at->format('d/m H:i:s'),
                $mensagem->sent_at ? 'enviada' : 'recebida',
                $atual->format('d/m H:i:s'),
                $atual->copy()->addHours($offset)->format('d/m H:i:s'),
            ));
        }

        $this->line('');

        if (! $aplicar) {
            $this->info('Simulação. Use --apply para executar.');

            return self::SUCCESS;
        }

        // Uma consulta por coluna: o deslocamento e o mesmo para todas as
        // linhas, e percorrer registro a registro so multiplicaria o risco de
        // parar no meio.
        $corrigidas = DB::transaction(function () use ($alvos, $offset): int {
            $ids = (clone $alvos)->pluck('id');

            ConversationMessage::query()->whereIn('id', $ids)->whereNotNull('sent_at')
                ->update(['sent_at' => DB::raw("date_add(sent_at, interval {$offset} hour)")]);

            ConversationMessage::query()->whereIn('id', $ids)->whereNotNull('received_at')
                ->update(['received_at' => DB::raw("date_add(received_at, interval {$offset} hour)")]);

            return $ids->count();
        });

        $audit->log('conversations.timestamps_timezone_fixed', 'Horários de mensagem convertidos para o fuso local.', null, null, [
            'total' => $corrigidas,
            'offset_horas' => $offset,
            'tolerancia_minutos' => $tolerancia,
        ]);

        $this->info("{$corrigidas} mensagem(ns) corrigida(s).");

        return self::SUCCESS;
    }
}
